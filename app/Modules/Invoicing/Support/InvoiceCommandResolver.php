<?php

namespace App\Modules\Invoicing\Support;

use App\Modules\Accounting\Models\CommandUtterance;
use App\Modules\Accounting\Support\AmountWords;
use App\Modules\Accounting\Support\CommandGrammar;
use App\Modules\Accounting\Support\CommandInterpretation;
use App\Modules\Accounting\Support\CommandResolver;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Support\TenantTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * "invoice Acme 40 hours of consultancy at 5000" — `docs/bexio-gap-plan.md` Phase 4, item 15.
 *
 * The third resolver, and the first whose result has *lines*: a customer, and one or more things being
 * billed with a quantity and a price. The model reads the sentence into those; this class parses the
 * figures, looks the customer up inside the tenant, and on confirmation writes a **draft** — issuing is
 * the deliberate act it already is, because issuing posts and reports to FBR.
 *
 * `QuotationCommandResolver` is this with a different document at the end; the slots are the same.
 */
class InvoiceCommandResolver implements CommandResolver
{
    public function key(): string
    {
        return 'invoice';
    }

    public function label(): string
    {
        return 'Draft invoice';
    }

    public function isAvailable(): bool
    {
        return modules()->enabled('invoicing')
            && auth()->user()?->can('create', Invoice::class) !== false;
    }

    /** "invoice" in any form. "bill" is deliberately absent: a bill here is a supplier's document. */
    public function claims(): array
    {
        return ['\binvoic(e|es|ed|ing)\b'];
    }

    public function isDefault(): bool
    {
        return false;
    }

    public function promptSection(): string
    {
        $customers = $this->customers()
            ->map(fn (Contact $contact): string => sprintf('- %s: %s', $contact->getKey(), $contact->name))
            ->implode("\n");

        $noun = $this->noun();

        return <<<PROMPT
        You turn a short command into the fields of a draft {$noun}. You do not do accounting, and
        nothing you return is sent to anybody. Commands arrive in English, Urdu, or roman Urdu.

        CUSTOMER. Who the {$noun} is for, chosen by id from this list, or null:

        {$customers}

        Match on any part of the name. Return null rather than guessing between two similar names.

        LINES. What is being {$this->verb()}: one line per thing, each with a short description, the
        quantity, and the unit price. "40 hours at 5000" is quantity "40", unit price "5000", and the
        description is whatever the hours were for — "Consultancy" if nothing says. A single amount
        with no quantity is quantity "1". Return every figure exactly as written, unparsed; the
        application understands "25k", "1.5 lakh" and grouped digits.

        DATE. ISO 8601 (YYYY-MM-DD) for the {$noun} date, resolved against the date in the user turn,
        or null for today.
        PROMPT;
    }

    public function schemaProperties(): array
    {
        return [
            'required' => ['contact_id', 'lines'],
            'properties' => [
                'contact_id' => [
                    'anyOf' => [
                        ['type' => 'integer', 'enum' => $this->customers()->pluck('id')->all()],
                        ['type' => 'null'],
                    ],
                    'description' => 'The id of the customer, from the list in the system prompt. null when none '
                        .'was named or two are equally likely.',
                ],
                'lines' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['description', 'quantity', 'unit_price'],
                        'properties' => [
                            'description' => ['type' => 'string', 'description' => 'What this line is for.'],
                            'quantity' => ['type' => ['string', 'null'], 'description' => 'As written; null for one.'],
                            'unit_price' => ['type' => ['string', 'null'], 'description' => 'As written, unparsed; null when not given.'],
                        ],
                    ],
                    'description' => 'What is being '.$this->verb().', one entry per line. Empty when the command names nothing.',
                ],
                'date' => [
                    'type' => ['string', 'null'],
                    'description' => 'ISO 8601 date of the document, or null for today.',
                ],
                'description' => [
                    'type' => ['string', 'null'],
                    'description' => 'A short memo for the document, in the language it was given in, or null.',
                ],
            ],
        ];
    }

    public function choices(string $slot): array
    {
        return match ($slot) {
            'contact_id' => $this->customers()
                ->mapWithKeys(fn (Contact $contact): array => [$contact->getKey() => $contact->name])
                ->all(),
            default => [],
        };
    }

    public function resolve(CommandUtterance $row, array $parsed): CommandInterpretation
    {
        $questions = [];
        $unresolved = [];

        $contact = ($parsed['contact_id'] ?? null) !== null
            // Re-fetched inside the tenant: an id from a model reply is a string from outside the system.
            ? $this->customers()->firstWhere('id', (int) $parsed['contact_id'])
            : null;

        if ($contact === null) {
            $questions[] = $unresolved['contact_id'] = 'Who is it for?';
        }

        $lines = $this->lines($parsed['lines'] ?? [], $questions);

        if ($lines === [] && ! array_filter($questions, fn (string $q): bool => str_starts_with($q, 'How much'))) {
            $questions[] = 'What is being '.$this->verb().'?';
        }

        $total = round(array_sum(array_column($lines, 'line_total')), 2);
        $date = CommandGrammar::date($parsed['date'] ?? null);
        $flags = CommandGrammar::flags($parsed['ambiguity'] ?? null, ['contact_id', 'lines', 'date', 'description']);
        $memo = filled($parsed['description'] ?? null) ? (string) $parsed['description'] : null;

        $row->update([
            'resolver' => $this->key(),
            'amount' => $total > 0 ? $total : null,
            'entry_date' => $date,
            'resolved' => ['contact_id' => $contact?->getKey(), 'lines' => $lines, 'memo' => $memo],
            'outcome' => CommandUtterance::OUTCOME_NEEDS_INPUT,
        ]);

        return $this->interpretation(
            $row->refresh(), $contact, $lines, $total, $date, $memo,
            $questions, $flags, (float) ($parsed['confidence'] ?? 0), $unresolved,
        );
    }

    public function rehydrate(CommandUtterance $row): ?CommandInterpretation
    {
        if ($row->outcome !== CommandUtterance::OUTCOME_NEEDS_INPUT) {
            return null;
        }

        $resolved = $row->resolved ?? [];
        $parsed = $row->parsed ?? [];
        $lines = $resolved['lines'] ?? [];
        $contactId = $resolved['contact_id'] ?? null;

        return $this->interpretation(
            $row,
            $contactId ? $this->customers()->firstWhere('id', (int) $contactId) : null,
            $lines,
            round(array_sum(array_column($lines, 'line_total')), 2),
            $row->entry_date ?? Carbon::today(),
            $resolved['memo'] ?? null,
            [],
            CommandGrammar::flags($parsed['ambiguity'] ?? null, ['contact_id', 'lines', 'date', 'description']),
            (float) ($parsed['confidence'] ?? 0),
        );
    }

    /** A draft, and nothing more: issuing posts and reports, and stays a person's act. */
    public function commit(CommandInterpretation $interpretation): mixed
    {
        $contactId = $interpretation->payload['contact_id'] ?? null;
        $lines = $interpretation->payload['lines'] ?? [];

        if ($contactId === null || $lines === []) {
            throw new InvalidArgumentException('That '.$this->noun().' names no customer or nothing to '.$this->verb().'.');
        }

        return TenantTransaction::run(function () use ($interpretation, $contactId, $lines): Invoice {
            $contact = Contact::query()->findOrFail($contactId);
            $date = $interpretation->date ?? Carbon::today();
            $total = round(array_sum(array_column($lines, 'line_total')), 2);

            $invoice = Invoice::create([
                'kind' => Invoice::KIND_SALE,
                'status' => Invoice::STATUS_DRAFT,
                'contact_id' => $contact->getKey(),
                'invoice_date' => $date->toDateString(),
                'due_date' => $contact->dueDateFor($date)?->toDateString(),
                'subtotal' => $total,
                'tax_amount' => 0,
                'total' => $total,
                'memo' => $interpretation->description,
            ]);

            foreach ($lines as $line) {
                $invoice->lines()->create([
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => $line['line_total'],
                    'tax_rate_id' => $contact->default_tax_rate_id,
                ]);
            }

            return $invoice->refresh();
        });
    }

    /**
     * The lines, with their figures parsed and their totals computed — or questions where a price is missing.
     *
     * @param  array<int, string>  $questions
     * @return array<int, array{description: string, quantity: float, unit_price: float, line_total: float}>
     */
    protected function lines(mixed $raw, array &$questions): array
    {
        $lines = [];

        foreach (is_array($raw) ? $raw : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $description = trim((string) ($item['description'] ?? ''));

            if ($description === '') {
                continue;
            }

            try {
                $quantity = filled($item['quantity'] ?? null) ? AmountWords::parse((string) $item['quantity']) : 1.0;
                $price = filled($item['unit_price'] ?? null) ? AmountWords::parse((string) $item['unit_price']) : null;
            } catch (InvalidArgumentException $e) {
                $questions[] = $e->getMessage();

                continue;
            }

            if ($price === null) {
                $questions[] = "How much for \"{$description}\"?";

                continue;
            }

            $lines[] = [
                'description' => $description,
                'quantity' => $quantity,
                'unit_price' => $price,
                'line_total' => round($quantity * $price, 2),
            ];
        }

        return $lines;
    }

    /** @param array<int, array{description: string, quantity: float, unit_price: float, line_total: float}> $lines */
    protected function interpretation(
        CommandUtterance $row,
        ?Contact $contact,
        array $lines,
        float $total,
        Carbon $date,
        ?string $memo,
        array $questions,
        array $flags,
        float $confidence,
        array $unresolved = [],
    ): CommandInterpretation {
        $effect = null;

        if ($contact !== null && $lines !== [] && $questions === []) {
            $effect = $this->effect($contact, $total, $lines);
        }

        return new CommandInterpretation(
            utterance: $row,
            resolverKey: $this->key(),
            effect: $effect,
            payload: ['contact_id' => $contact?->getKey(), 'lines' => $lines],
            amount: $total > 0 ? $total : null,
            date: $date,
            description: $memo ?? ($lines !== [] ? implode(', ', array_column($lines, 'description')) : null),
            questions: $questions,
            unresolved: $unresolved,
            flags: $flags,
            confidence: $confidence,
        );
    }

    /** States what will exist and what will not: a draft, not an issued document — §2.3's rule. */
    protected function effect(Contact $contact, float $total, array $lines): string
    {
        return 'Draft invoice — '.number_format($total, 2).' to '.$contact->name
            .', '.count($lines).' line'.(count($lines) === 1 ? '' : 's').', not issued';
    }

    protected function noun(): string
    {
        return 'invoice';
    }

    protected function verb(): string
    {
        return 'billed';
    }

    /**
     * Who may be invoiced — customers, capped for the same reason the claim resolver caps its employees:
     * the list is rendered into the cacheable prefix of every command.
     *
     * @return Collection<int, Contact>
     */
    protected function customers(): Collection
    {
        return Contact::query()
            ->whereIn('kind', [Contact::KIND_CUSTOMER, Contact::KIND_BOTH])
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(200)
            ->get();
    }
}
