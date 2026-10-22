<?php

namespace App\Modules\Expenses\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Support\PayrollAccounts;
use App\Modules\Expenses\Models\ExpenseClaim;
use App\Support\ModuleMap;
use RuntimeException;

/**
 * The liability an approved claim is, in an account — `docs/bexio-gap-plan.md` Phase 3, item 13.
 *
 * `ExpenseReports` named the hole: between approval and payday a claim was money the company owed and
 * appeared in no account, and when the payslip finally reimbursed it the whole thing landed as an
 * expense in `5600`, shared with meal recoveries. Now approval posts it — the claim's own category as the
 * expense, and a liability for what is owed — and the payslip's reimbursement line clears the liability.
 *
 * **The credit side is whatever payroll will debit.** It is read from the same mapping
 * (`PayrollAccounts::id('expense_reimbursement')`, shipped as 2310 Expense Reimbursements Payable), so
 * the two legs net on the same account whatever a company has pointed it at: a company that kept the old
 * `5600` has the expense in its category and 5600 washing to nil, which is still right.
 *
 * Guarded on Accounting the way every Expenses → Accounting path is: without it a claim is approved and
 * nothing posts, as before.
 */
class ExpenseClaimPosting
{
    public function __construct(private JournalEntryService $entries) {}

    public function accrue(ExpenseClaim $claim): ?JournalEntry
    {
        if ($claim->journal_entry_id || ! modules()->enabled('accounting')) {
            return null;
        }

        // The claim's own category; failing that, Miscellaneous — the type, then the account it is seeded
        // to point at, so a company that has never seeded its transaction types still books the claim.
        $expenseAccount = $claim->transactionType?->account_id
            ?? TransactionType::byCode('miscellaneous')?->account_id
            ?? Account::query()->where('code', '5900')->value('id');

        if (! $expenseAccount) {
            throw new RuntimeException(
                "Claim #{$claim->id} has no category with an account, and there is no Miscellaneous expense "
                .'account (5900) to fall back on. Give the claim a category first.'
            );
        }

        $amount = round((float) $claim->amount, 2);
        $employee = $claim->employee?->user?->name ?? $claim->employee?->employee_id ?? "employee #{$claim->employee_id}";

        $entry = $this->entries->create([
            'entry_date' => now()->toDateString(),
            'entry_type' => 'general',
            'memo' => "Expense claim #{$claim->id} — {$claim->description} ({$employee})",
            'transaction_type_id' => $claim->transaction_type_id,
            'source_type' => ModuleMap::alias(ExpenseClaim::class),
            'source_id' => $claim->getKey(),
        ], [
            ['account_id' => $expenseAccount, 'debit_amount' => $amount, 'description' => $claim->description],
            [
                'account_id' => PayrollAccounts::id('expense_reimbursement'),
                'credit_amount' => $amount,
                'description' => "Owed to {$employee} until reimbursed",
            ],
        ]);

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        $posted = $this->entries->post($entry);

        $claim->forceFill(['journal_entry_id' => $posted->getKey()])->save();

        return $posted;
    }

    /** Take the accrual back — the claim is being deleted after approval. */
    public function reverse(ExpenseClaim $claim): void
    {
        $entry = $claim->journalEntry;

        if ($entry === null || ! $entry->is_posted) {
            return;
        }

        $this->entries->reverse($entry);
    }
}
