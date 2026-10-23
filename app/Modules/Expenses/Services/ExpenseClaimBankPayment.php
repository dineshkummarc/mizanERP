<?php

namespace App\Modules\Expenses\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\CompanyBankAccount;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Support\PayrollAccounts;
use App\Modules\Expenses\Models\ExpenseClaim;
use App\Support\ModuleMap;
use App\Support\TenantTransaction;
use InvalidArgumentException;

/**
 * An approved claim paid by bank transfer instead of on the payslip — `docs/bexio-gap-plan.md` §3.6.
 *
 * The one thing Phase 3 said could not be built: the bank file pays the *employee*, and nothing on an
 * employee payment could say it was settling a claim without Accounting naming Expenses. The
 * `PayableDocument` contract from the same phase is the answer — the claim is the payable, it names the
 * employee as the party the file pays, and it posts its own settlement when the payment is approved,
 * against the liability its approval booked.
 *
 * **Raised, the claim leaves the payroll pool at once.** A draft payment waiting in the next file and
 * a payslip that also reimbursed it would pay the person twice; `payment_id` is the flag, and
 * `expense_claims.payment_id` nulls on delete, so a draft discarded before it went returns the claim
 * to payroll by itself.
 */
class ExpenseClaimBankPayment
{
    /** The transaction type every claim payment is filed under, created on first use. */
    public const TRANSACTION_CODE = 'expense-reimbursement';

    public function __construct(
        private JournalEntryService $entries,
        private ExpenseClaimPosting $posting,
    ) {}

    /** A draft payment for the next bank file, and the claim marked as spoken for. */
    public function raise(ExpenseClaim $claim, ?int $bankAccountId = null, ?string $valueDate = null): Payment
    {
        if ($claim->status !== ExpenseClaim::STATUS_APPROVED) {
            throw new InvalidArgumentException("Claim #{$claim->id} is {$claim->status}; only an approved claim is paid.");
        }

        if ($claim->payment_id) {
            throw new InvalidArgumentException("Claim #{$claim->id} already has bank payment #{$claim->payment_id} raised for it.");
        }

        if (! modules()->enabled('accounting')) {
            throw new InvalidArgumentException('Paying a claim by bank transfer needs the Accounting module; without it, payroll reimburses it.');
        }

        $employee = $claim->employee;

        if (! $employee) {
            throw new InvalidArgumentException("Claim #{$claim->id} names no employee to pay.");
        }

        return TenantTransaction::run(function () use ($claim, $bankAccountId, $valueDate): Payment {
            $payment = Payment::create([
                'payable_type' => ModuleMap::alias(ExpenseClaim::class),
                'payable_id' => $claim->getKey(),
                'transaction_type_id' => $this->transactionType()->getKey(),
                'company_bank_account_id' => $bankAccountId ?? CompanyBankAccount::query()
                    ->where('is_active', true)
                    ->orderByDesc('is_default')
                    ->value('id'),
                'amount' => round((float) $claim->amount, 2),
                'reference' => "CLAIM-{$claim->id}",
                'details' => "Expense claim #{$claim->id} — {$claim->description}",
                'value_date' => $valueDate ?? now()->toDateString(),
                'status' => Payment::STATUS_DRAFT,
            ]);

            $claim->forceFill(['payment_id' => $payment->getKey()])->save();

            return $payment;
        });
    }

    /**
     * The settlement a payment's approval makes — `PayableDocument::settleByPayment()`.
     *
     * Debits the liability approval credited (whatever account payroll's reimbursement line maps to) and
     * credits the bank, so the claim nets to nil on 2310 exactly as a payslip would have cleared it. A
     * claim approved before accrual began has no liability yet; it is accrued here first, so the expense
     * is booked once and the clearing has something to clear.
     */
    public function settle(ExpenseClaim $claim, Payment $payment): ?JournalEntry
    {
        if ($claim->isSettled()) {
            throw new InvalidArgumentException("Claim #{$claim->id} is already settled.");
        }

        return TenantTransaction::run(function () use ($claim, $payment): ?JournalEntry {
            return $this->post($claim, $payment);
        });
    }

    private function post(ExpenseClaim $claim, Payment $payment): JournalEntry
    {
        $this->posting->accrue($claim);

        $amount = round((float) $payment->amount, 2);
        $employee = $claim->employee?->user?->name ?? $claim->employee?->employee_id ?? "employee #{$claim->employee_id}";

        $entry = $this->entries->create([
            'entry_date' => ($payment->value_date ?? now())->toDateString(),
            'entry_type' => 'general',
            'memo' => "Expense claim #{$claim->id} reimbursed by bank transfer — {$employee}",
            'transaction_type_id' => $payment->transaction_type_id,
            'source_type' => ModuleMap::alias(ExpenseClaim::class),
            'source_id' => $claim->getKey(),
        ], [
            [
                'account_id' => PayrollAccounts::id('expense_reimbursement'),
                'debit_amount' => $amount,
                'description' => "Reimbursement to {$employee} — claim #{$claim->id}",
            ],
            [
                'account_id' => Account::query()->where('code', '1100')->value('id'),
                'credit_amount' => $amount,
                'description' => "Bank payment #{$payment->getKey()}",
            ],
        ]);

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        $posted = $this->entries->post($entry);

        $claim->forceFill(['status' => ExpenseClaim::STATUS_SETTLED, 'payment_id' => $payment->getKey()])->save();

        return $posted;
    }

    private function transactionType(): TransactionType
    {
        return TransactionType::firstOrCreate(
            ['code' => self::TRANSACTION_CODE],
            [
                'name' => 'Expense reimbursement',
                'account_id' => Account::query()->where('code', PayrollAccounts::code('expense_reimbursement'))->value('id'),
                'description' => 'Pays an approved expense claim by bank transfer; the claim itself carries the expense',
            ],
        );
    }
}
