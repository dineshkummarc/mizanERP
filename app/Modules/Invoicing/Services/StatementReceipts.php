<?php

namespace App\Modules\Invoicing\Services;

use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\BankReconciliationService;
use App\Modules\Invoicing\Models\Invoice;
use App\Support\TenantTransaction;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * A receipt recorded straight from the bank statement line that is it — `docs/bexio-gap-plan.md`
 * Phase 3, item 12, the step bexio collapses.
 *
 * Until this, money arriving against an invoice was two trips: key the receipt on the invoice, then
 * match the ledger line it made to the statement line. This does both from the line: the invoice is
 * settled through `InvoiceService::recordPayment()` into the statement's own account, and the cash line
 * that posting wrote is matched to the statement line in the same transaction.
 *
 * **One line, one invoice, the whole line.** A transfer that pays five invoices is the Record a receipt
 * screen's job; a partial here would match a ledger line to a statement line it does not equal, which
 * is a reconciliation that completes and lies.
 */
class StatementReceipts
{
    public function __construct(
        private InvoiceService $invoices,
        private BankReconciliationService $reconciliation,
    ) {}

    public function record(BankStatementLine $line, Invoice $invoice): BankStatementLine
    {
        if ($line->isMatched()) {
            throw new InvalidArgumentException('This statement line is already matched.');
        }

        if ((float) $line->amount <= 0) {
            throw new InvalidArgumentException('Money went out on this line; a receipt is money coming in.');
        }

        if (! $invoice->isSale()) {
            throw new InvalidArgumentException("{$invoice->invoice_number} is not a customer invoice.");
        }

        $statement = $line->bankStatement;
        $amount = round((float) $line->amount, 2);

        return TenantTransaction::run(function () use ($line, $invoice, $statement, $amount): BankStatementLine {
            $this->invoices->recordPayment(
                $invoice,
                $amount,
                $line->transaction_date->toDateString(),
                null,
                $statement->account_id,
                filled($line->reference) ? $line->reference : ($line->description ?: null),
            );

            $ledgerLine = JournalEntry::forSource(Invoice::class, $invoice->getKey())
                ->latest('id')
                ->firstOrFail()
                ->lines()
                ->where('account_id', $statement->account_id)
                ->where('debit_amount', $amount)
                ->firstOrFail();

            return $this->reconciliation->match($line, $ledgerLine);
        });
    }

    /**
     * Which invoice this line probably pays: the one open invoice owed exactly this much, or the one
     * whose number appears in the narration. Null when neither is unambiguous.
     */
    public function suggest(BankStatementLine $line): ?Invoice
    {
        $open = $this->openInvoices();
        $amount = round((float) $line->amount, 2);

        $byAmount = $open->filter(fn (Invoice $invoice): bool => abs($invoice->outstanding() - $amount) < 0.005);

        if ($byAmount->count() === 1) {
            return $byAmount->first();
        }

        $text = strtolower(trim($line->description.' '.$line->reference));

        $byNumber = $open->filter(fn (Invoice $invoice): bool => $text !== ''
            && str_contains($text, strtolower($invoice->invoice_number)));

        return $byNumber->count() === 1 ? $byNumber->first() : null;
    }

    /** @return Collection<int, Invoice> */
    public function openInvoices(): Collection
    {
        return Invoice::query()
            ->where('kind', Invoice::KIND_SALE)
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])
            ->with('contact')
            ->orderByDesc('invoice_date')
            ->limit(200)
            ->get();
    }
}
