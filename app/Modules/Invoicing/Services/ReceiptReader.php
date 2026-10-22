<?php

namespace App\Modules\Invoicing\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\AmountWords;
use App\Modules\Accounting\Support\CommandGrammar;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Support\Ai\StructuredModel;
use App\Support\TenantTransaction;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * A photographed receipt into a draft bill — `docs/bexio-gap-plan.md` Phase 4, item 14.
 *
 * bexio's headline feature, built on three things that already existed: `StructuredModel`, which turns
 * input into a fixed set of fields and nothing else; the bill's `attachment_path` (Phase 2), which is
 * where the photo lives afterwards; and the company's own chart, which is the only list the model may
 * choose an account from.
 *
 * **The result is a draft a person confirms.** The same rule as the command bar and the statutory
 * contributions — the model resolves pixels into values, and nothing it says reaches the ledger until
 * somebody issues the bill. A wrong supplier, total or account is one field edit on the draft.
 *
 * **Suppliers resolve by NTN first, then by name, and are created last.** A bill needs a party, and a
 * draft that stopped to ask would be the entry screen with extra steps; a new supplier is flagged in the
 * memo so the person checking the draft knows to look.
 */
class ReceiptReader
{
    /** What a receipt may be. The extension decides how the model receives it. */
    public const MEDIA_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'pdf' => 'application/pdf',
    ];

    public function __construct(private StructuredModel $model) {}

    /**
     * The fields the model read off the file at this path on the public tenant disk.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException for a file that is not a photo or a PDF
     * @throws \App\Support\Ai\ModelUnavailable when the configured driver cannot read documents
     */
    public function read(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mediaType = self::MEDIA_TYPES[$extension]
            ?? throw new InvalidArgumentException("A receipt is a photo or a PDF; \".{$extension}\" is neither.");

        if (! Storage::disk('public')->exists($path)) {
            throw new InvalidArgumentException('The uploaded receipt could not be found.');
        }

        return $this->model->extractFromDocument(
            $this->system(),
            // Today's date in the user turn, never the system prompt — the same cache rule the command bar keeps.
            sprintf("Today is %s.\n\nRead this receipt or supplier invoice and fill the fields.", now()->toDateString()),
            (string) Storage::disk('public')->get($path),
            $mediaType,
            $this->schema(),
        );
    }

    /**
     * The stable half of the prompt: this company's expense accounts and the rules. Cacheable per tenant.
     */
    public function system(): string
    {
        $accounts = $this->expenseAccounts()
            ->map(fn (Account $account): string => sprintf('- %s: %s', $account->code, $account->name))
            ->implode("\n");

        return <<<PROMPT
        You read a photographed receipt or a supplier's invoice into structured fields. You do not do
        accounting, and you never invent a value you cannot see: null is the right answer for anything
        unreadable or absent.

        SUPPLIER. The business that issued the receipt — its trading name as printed, and its NTN
        (Pakistani national tax number, printed as seven digits, a dash and one digit, e.g. 1234567-8)
        when one is printed. Return the NTN exactly as printed.

        DATE. ISO 8601 (YYYY-MM-DD) for the date on the receipt, resolved against the date in the user
        turn when only a day and month are printed. null when none is printed.

        AMOUNTS. Return them exactly as printed, unparsed — "1,725.00", "Rs 1,725", "1725/-". The
        application understands grouped digits and currency marks. "total" is the amount payable
        including any tax; "tax" is the sales tax or GST line, or null when none is printed. Never
        compute one from the other.

        ACCOUNT. What the purchase was, chosen from this company's expense accounts, or null when
        nothing clearly fits:

        {$accounts}

        DESCRIPTION. One short line saying what was bought, in the language of the receipt — "Dinner,
        4 covers", "Toner cartridges x2". null when the receipt does not say.
        PROMPT;
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['supplier_name', 'supplier_ntn', 'date', 'total', 'tax', 'currency', 'description', 'account_code', 'confidence'],
            'properties' => [
                'supplier_name' => ['type' => ['string', 'null'], 'description' => 'The issuing business, as printed.'],
                'supplier_ntn' => ['type' => ['string', 'null'], 'description' => 'The printed NTN, exactly, or null.'],
                'date' => ['type' => ['string', 'null'], 'description' => 'ISO 8601 date on the receipt, or null.'],
                'total' => ['type' => ['string', 'null'], 'description' => 'The amount payable, as printed, unparsed.'],
                'tax' => ['type' => ['string', 'null'], 'description' => 'The tax line, as printed, or null when none is printed.'],
                'currency' => ['type' => ['string', 'null'], 'description' => 'ISO 4217 code when the receipt names one, else null.'],
                'description' => ['type' => ['string', 'null'], 'description' => 'One short line saying what was bought.'],
                'account_code' => [
                    // anyOf rather than a type array: structured outputs refuses `type: [...]` beside an enum.
                    'anyOf' => [
                        ['type' => 'string', 'enum' => $this->expenseAccounts()->pluck('code')->all()],
                        ['type' => 'null'],
                    ],
                    'description' => 'The expense account this belongs to, from the list in the system prompt, or null.',
                ],
                'confidence' => ['type' => 'number', 'description' => '0 to 1. How legible the receipt was and how sure you are.'],
            ],
        ];
    }

    /**
     * The draft bill, from what was read.
     *
     * @param  array<string, mixed>  $fields  what {@see read()} returned
     * @param  string  $attachmentPath  the receipt, already on the public tenant disk, kept on the bill
     *
     * @throws InvalidArgumentException when there is no total or no supplier to make a bill out of
     */
    public function draft(array $fields, string $attachmentPath): Invoice
    {
        $total = $this->amount($fields['total'] ?? null);

        if ($total === null || $total <= 0) {
            throw new InvalidArgumentException(
                'No total could be read off the receipt. Key the bill by hand and attach the file to it.'
            );
        }

        $tax = $this->amount($fields['tax'] ?? null) ?? 0.0;

        // A "tax" the size of the bill is a misread, not a tax. Dropped rather than trusted; the draft
        // shows the whole amount and the person corrects it.
        if ($tax < 0 || $tax >= $total) {
            $tax = 0.0;
        }

        $net = round($total - $tax, 2);

        [$supplier, $created] = $this->supplier($fields);

        $date = CommandGrammar::date(filled($fields['date'] ?? null) ? (string) $fields['date'] : null);
        $accountCode = trim((string) ($fields['account_code'] ?? ''));
        $account = $accountCode !== '' ? Account::query()->where('code', $accountCode)->first() : null;
        $description = trim((string) ($fields['description'] ?? '')) ?: "Receipt from {$supplier->name}";

        return TenantTransaction::run(function () use ($supplier, $created, $date, $net, $tax, $total, $account, $description, $attachmentPath): Invoice {
            $bill = Invoice::create([
                'kind' => Invoice::KIND_PURCHASE,
                'status' => Invoice::STATUS_DRAFT,
                'contact_id' => $supplier->getKey(),
                'invoice_date' => $date->toDateString(),
                'due_date' => $supplier->dueDateFor($date)?->toDateString(),
                'subtotal' => $net,
                'tax_amount' => $tax,
                'tax_inclusive' => false,
                'total' => $total,
                'memo' => 'Read from a receipt'.($created ? " — supplier \"{$supplier->name}\" is new, check it" : ''),
                'attachment_path' => $attachmentPath,
            ]);

            $bill->lines()->create([
                'description' => $description,
                'quantity' => 1,
                'unit_price' => $net,
                'line_total' => $net,
                'account_id' => $account?->getKey(),
                'tax_amount' => $tax,
            ]);

            return $bill->refresh();
        });
    }

    /**
     * Who issued it: by NTN, then by name, then created.
     *
     * @param  array<string, mixed>  $fields
     * @return array{0: Contact, 1: bool} the supplier, and whether it was created just now
     */
    private function supplier(array $fields): array
    {
        $ntn = trim((string) ($fields['supplier_ntn'] ?? ''));
        $name = trim((string) ($fields['supplier_name'] ?? ''));

        if ($ntn !== '' && ($match = Contact::query()->where('ntn', $ntn)->first())) {
            return [$match, false];
        }

        if ($name !== '') {
            $match = Contact::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
                ?? Contact::query()->where('name', 'like', "%{$name}%")->orderBy('name')->first();

            if ($match) {
                return [$match, false];
            }
        }

        if ($name === '') {
            throw new InvalidArgumentException(
                'No supplier could be read off the receipt. Key the bill by hand and attach the file to it.'
            );
        }

        return [Contact::create([
            'name' => $name,
            'kind' => Contact::KIND_SUPPLIER,
            'ntn' => $ntn ?: null,
            'is_active' => true,
        ]), true];
    }

    private function amount(mixed $raw): ?float
    {
        if (! filled($raw)) {
            return null;
        }

        try {
            return AmountWords::parse((string) $raw);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @return \Illuminate\Support\Collection<int, Account> */
    private function expenseAccounts()
    {
        return Account::query()
            ->where('type', 'expense')
            ->where('is_active', true)
            ->where('allow_manual_entry', true)
            ->orderBy('code')
            ->get();
    }
}
