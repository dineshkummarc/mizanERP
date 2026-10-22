<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Support\PayrollAccounts;
use App\Support\Contracts\PayableDocument;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

class PaymentService
{
    public function __construct(private JournalEntryService $journalEntryService) {}

    /*
     * `generateSalaryPayments()` moved to Payroll\Services\SalaryPaymentGenerator.
     *
     * It read payslips, priced a salary from one, and copied the payslip's review state onto the payment —
     * all payroll's business, filed here because the row it writes is a Payment. Accounting still triggers
     * it, through the App\Events\RaisingMonthlyPayments event, so the bank payment file still raises the month's
     * payables when it is opened. See docs/module-packaging-plan.md §8 Group C.
     */
    /**
     * Approve a draft payment and post its journal entry: debit what the money
     * was for, credit Cash/Bank (1100).
     *
     * Posted here and now, as petty cash and invoices do. The entry used to be
     * left as a draft, which put every payment ever approved outside the Profit &
     * Loss and the Trial Balance — those read posted entries only — and outside
     * the approval queue as well, since a draft has not been submitted to anyone.
     */
    public function approve(Payment $payment): Payment
    {
        if ($payment->status !== Payment::STATUS_DRAFT) {
            throw new InvalidArgumentException("Payment #{$payment->id} is not a draft.");
        }

        if (! $payment->journal_entry_id) {
            $payment->journal_entry_id = $this->postEntryFor($payment)?->id;
        }

        $payment->status = Payment::STATUS_APPROVED;
        $payment->save();

        return $payment;
    }

    /**
     * The payment's journal entry, posted. Null when there is nothing to record.
     */
    public function postEntryFor(Payment $payment): ?JournalEntry
    {
        // A payslip can come out at nothing — a month entirely unpaid leave, a
        // salary wholly absorbed by deductions — and payroll still raises the
        // payment. There is no entry to make for zero, and refusing it would stop
        // the whole batch that row happens to be in.
        if (round((float) $payment->amount, 2) <= 0) {
            return null;
        }

        /*
         * A document that settles itself — a supplier bill, `docs/bexio-gap-plan.md` Phase 3.
         *
         * The bill already expensed what it bought and credited Accounts Payable when it was issued, so
         * the debit-and-credit below would book the cost twice. The document posts its own settlement
         * through the path that knows its currency, its status and its history, and hands the entry back.
         * Accounting asks the contract rather than naming the module; see PayableDocument.
         */
        $payable = $payment->loadMissing('payable')->payable;

        if ($payable instanceof PayableDocument) {
            $entry = $payable->settleByPayment($payment);

            return $entry instanceof JournalEntry ? $entry : null;
        }

        $type = $payment->transactionType;
        $cashAccount = Account::where('code', '1100')->first();

        if (! $cashAccount) {
            throw new RuntimeException('Account 1100 Cash/Bank not found.');
        }

        /*
         * Tax withheld at source — `docs/erpnext-gap-plan.md` Phase 4.
         *
         * Null for every payment until a company assigns a withholding section to a supplier, and this
         * whole block is then three lines instead of two: the money owed is still debited in full, the
         * bank is credited what actually leaves it, and the difference is a liability to FBR. Resolved
         * before the entry is created because the withheld figure is one of the entry's own lines.
         */
        $withholding = app(WithholdingService::class)->due($payment);
        $withheld = (float) ($withholding['amount'] ?? 0);
        $paid = round((float) $payment->amount - $withheld, 2);

        $entry = $this->journalEntryService->create([
            'entry_date' => ($payment->value_date ?? now())->toDateString(),
            'entry_type' => 'general',
            'memo' => "{$type?->name} payment — {$payment->details}",
            'transaction_type_id' => $type?->id,
            // What produced it — `docs/erpnext-gap-plan.md` Phase 1. A payment knows who it was paid to,
            // and for an employee that reaches their department.
            'source_type' => $payment::class,
            'source_id' => $payment->getKey(),
        ], array_values(array_filter([
            ['account_id' => $this->debitAccountFor($payment), 'debit_amount' => (float) $payment->amount, 'description' => $payment->details],
            ['account_id' => $cashAccount->id, 'credit_amount' => $paid, 'description' => $payment->details],
            $withholding ? [
                'account_id' => $withholding['account_id'],
                'credit_amount' => $withheld,
                'description' => sprintf(
                    'Tax withheld under %s at %s%%',
                    $withholding['section']->section,
                    rtrim(rtrim(number_format($withholding['rate'], 3), '0'), '.'),
                ),
            ] : null,
        ])));

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        $posted = $this->journalEntryService->post($entry);

        // After posting, because a deduction is a fact about an entry that exists: if the post is refused —
        // a frozen period, an unbalanced entry — there is no withholding either.
        if ($withholding) {
            app(WithholdingService::class)->record($payment, $withholding, $posted);
        }

        return $posted;
    }

    /**
     * What the money came off.
     *
     * A payment that settles a payslip clears Salaries Payable, because the
     * payslip already booked the wage as an expense and credited that liability.
     * Debiting the salary expense account again — which is where the salary
     * transaction type points — would book the same wage twice and leave the
     * liability standing for ever.
     *
     * Everything else debits whatever its transaction type is for.
     */
    protected function debitAccountFor(Payment $payment): int
    {
        $type = $payment->transactionType;

        // Either the payment belongs to a payslip, or it is salary being paid some
        // other way — arrears after a payslip was corrected, say, which cannot
        // carry the payslip's id because a payslip has only one payment. Both
        // settle wages a payslip has already expensed, so both clear the payable.
        if ($payment->payslip_id || $type?->code === Payment::SALARY_TRANSACTION_CODE) {
            return PayrollAccounts::id('salaries_payable');
        }

        // Refused rather than skipped. This silently booked nothing at all when
        // the type had no account — the payment went out, was marked approved, and
        // never reached the ledger.
        if (! $type?->account_id) {
            throw new RuntimeException(
                "Payment #{$payment->id} cannot be booked: the '".($type?->name ?? 'unknown')
                ."' transaction type has no account. Set one under Accounting → Transaction Types."
            );
        }

        return $type->account_id;
    }

    /**
     * Send a set of payments out as one batch.
     *
     * Stamps every payment with the same reference and marks it exported, which
     * is what keeps it out of the next batch — a month's salaries can leave in
     * several files as employees accept their payslips, and nothing may go twice.
     *
     * Payments that are not releasable are skipped rather than refused: the
     * caller has already filtered, and failing the whole batch because one row
     * changed underneath would leave the operator with no way forward. Returns
     * the payments that actually went.
     *
     * @param  iterable<Payment>  $payments
     * @return Collection<int, Payment>
     */
    public function release(iterable $payments, string $reference): Collection
    {
        $released = collect();
        $now = now();

        foreach ($payments as $payment) {
            if (! $payment->isReleasable()) {
                continue;
            }

            // A draft on its way to the bank is approved first, so that money
            // leaving the company is in the books by definition. Releasing used to
            // stamp the status and nothing else, and a payment released without
            // being approved booked nothing at all — no entry, no expense, no trace
            // in the Profit & Loss, while the bank file went out regardless.
            if ($payment->status === Payment::STATUS_DRAFT) {
                $this->approve($payment);
            }

            $payment->update([
                'status' => Payment::STATUS_EXPORTED,
                'batch_reference' => $reference,
                'released_at' => $now,
            ]);

            $released->push($payment);
        }

        return $released;
    }

    /**
     * Undo a release: the batch's payments go back in the pool and will appear in
     * the next one.
     *
     * For a file the bank rejected, or one built by mistake. Each payment goes
     * back to what it was before the release — see restoreToPool(). To put a
     * single row back without re-issuing the whole file, use revertExport().
     *
     * Refuses outright if any payment in the batch has been marked paid. That
     * says the money actually moved, and putting such a row back in the pool
     * would queue it to move again.
     *
     * @return Collection<int, Payment> the payments restored
     *
     * @throws InvalidArgumentException when the batch is unknown or partly paid
     */
    public function voidBatch(string $reference): Collection
    {
        $payments = Payment::inBatch($reference)->get();

        if ($payments->isEmpty()) {
            throw new InvalidArgumentException("No payments belong to batch {$reference}.");
        }

        $paid = $payments->where('status', Payment::STATUS_PAID);

        if ($paid->isNotEmpty()) {
            throw new InvalidArgumentException(
                "Batch {$reference} cannot be voided: {$paid->count()} of its payments are already marked paid, "
                .'so the money has moved. Reverse those individually instead.'
            );
        }

        foreach ($payments as $payment) {
            $this->restoreToPool($payment);
        }

        activity('Payment')
            ->withProperties([
                'batch_reference' => $reference,
                'payments' => $payments->pluck('id')->all(),
                'total' => round((float) $payments->sum('amount'), 2),
            ])
            ->log("Voided payment batch {$reference} — ".$payments->count().' payment(s) returned to the next batch');

        return $payments;
    }

    /**
     * Put a single exported payment back in the pool.
     *
     * The batch-level void is the usual way back, but a file is not always wrong
     * as a whole — one row bounces, or one payee's details were stale — and
     * re-issuing the entire batch to fix one payment is worse than fixing the one.
     *
     * @throws InvalidArgumentException when the payment never went out, or has
     *                                  been marked paid
     */
    public function revertExport(Payment $payment): Payment
    {
        if ($payment->status === Payment::STATUS_PAID) {
            throw new InvalidArgumentException(
                "Payment #{$payment->id} is marked paid, so the money has moved. Reverse it rather than "
                .'putting it back in the pool.'
            );
        }

        if ($payment->status !== Payment::STATUS_EXPORTED) {
            throw new InvalidArgumentException("Payment #{$payment->id} has not been exported.");
        }

        $batch = $payment->batch_reference;

        $this->restoreToPool($payment);

        activity('Payment')
            ->performedOn($payment)
            ->withProperties([
                'batch_reference' => $batch,
                'restored_to' => $payment->status,
                'amount' => (float) $payment->amount,
            ])
            ->log("Payment #{$payment->id} reverted from exported"
                .($batch ? " (was in batch {$batch})" : '').' and returned to the next batch');

        return $payment;
    }

    /**
     * Back to whatever it was before the release, and out of its batch.
     *
     * The prior status is reconstructed rather than stored, and exactly: approve()
     * is the only thing that ever sets journal_entry_id on a payment, and it sets
     * the status to approved at the same time — so a released payment carrying a
     * journal entry was approved before it went, and one without was a draft.
     */
    protected function restoreToPool(Payment $payment): void
    {
        $payment->update([
            'status' => $payment->journal_entry_id ? Payment::STATUS_APPROVED : Payment::STATUS_DRAFT,
            'batch_reference' => null,
            'released_at' => null,
        ]);
    }

    /**
     * Batches that could still be voided, newest first: those whose payments are
     * exported and none of them paid.
     *
     * @return Collection<string, string> reference => label
     */
    public function voidableBatches(int $limit = 20): Collection
    {
        return Payment::query()
            ->whereNotNull('batch_reference')
            ->where('status', Payment::STATUS_EXPORTED)
            ->get()
            ->groupBy('batch_reference')
            ->sortByDesc(fn (Collection $payments) => $payments->max('released_at'))
            ->take($limit)
            ->map(function (Collection $payments, string $reference): string {
                $released = $payments->max('released_at');

                return sprintf(
                    '%s — %d payment(s), %s%s',
                    $reference,
                    $payments->count(),
                    number_format((float) $payments->sum('amount'), 2),
                    $released ? ', released '.$released->format('d M Y H:i') : '',
                );
            });
    }

    /**
     * The next unused reference for a prefix, e.g. SAL-2026-07 -> SAL-2026-07-B2
     * when B1 has already gone.
     *
     * Numbered from what is stored rather than a counter, so it stays correct if
     * a batch is voided or the table is restored from a backup.
     */
    public function nextBatchReference(string $prefix): string
    {
        $used = Payment::query()
            ->where('batch_reference', 'like', $prefix.'-B%')
            ->pluck('batch_reference')
            ->map(fn (string $reference): int => (int) str($reference)->afterLast('-B')->toString())
            ->filter()
            ->max() ?? 0;

        return $prefix.'-B'.($used + 1);
    }
}
