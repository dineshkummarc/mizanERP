<?php

namespace App\Modules\Quotations\Support;

use App\Modules\Accounting\Support\CommandInterpretation;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Support\InvoiceCommandResolver;
use App\Modules\Quotations\Models\Quotation;
use App\Support\TenantTransaction;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * "quote Acme 40 hours at 5000" — `docs/bexio-gap-plan.md` Phase 4, item 15.
 *
 * The invoice resolver with a quote at the end. Same slots — a customer, lines, a date — because a
 * quote and an invoice are the same shape until one of them posts; the difference is the document
 * written on confirmation, and that it is sent rather than issued. Quotations requires Invoicing, so
 * extending its resolver costs the boundary nothing.
 */
class QuotationCommandResolver extends InvoiceCommandResolver
{
    public function key(): string
    {
        return 'quotation';
    }

    public function label(): string
    {
        return 'Draft quote';
    }

    public function isAvailable(): bool
    {
        return modules()->enabled('quotations')
            && auth()->user()?->can('create', Quotation::class) !== false;
    }

    public function claims(): array
    {
        return ['\bquot(e|es|ed|ing|ation|ations)\b'];
    }

    /** A draft quote, which Send on its row then fixes and emails — never sent from here. */
    public function commit(CommandInterpretation $interpretation): mixed
    {
        $contactId = $interpretation->payload['contact_id'] ?? null;
        $lines = $interpretation->payload['lines'] ?? [];

        if ($contactId === null || $lines === []) {
            throw new InvalidArgumentException('That quote names no customer or nothing to quote.');
        }

        return TenantTransaction::run(function () use ($interpretation, $contactId, $lines): Quotation {
            $contact = Contact::query()->findOrFail($contactId);
            $date = $interpretation->date ?? Carbon::today();

            $quotation = Quotation::create([
                'contact_id' => $contact->getKey(),
                'issue_date' => $date->toDateString(),
                'valid_until' => $date->copy()->addDays(30)->toDateString(),
                'notes' => $interpretation->description,
            ]);

            foreach ($lines as $line) {
                $quotation->lines()->create([
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'discount_pct' => (float) $contact->default_discount_pct,
                ]);
            }

            return $quotation->fresh('lines')->recalculate();
        });
    }

    protected function effect(Contact $contact, float $total, array $lines): string
    {
        return 'Draft quote — '.number_format($total, 2).' for '.$contact->name
            .', '.count($lines).' line'.(count($lines) === 1 ? '' : 's').', not sent';
    }

    protected function noun(): string
    {
        return 'quote';
    }

    protected function verb(): string
    {
        return 'quoted';
    }
}
