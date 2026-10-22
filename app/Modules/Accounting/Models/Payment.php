<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Accounting\Support\BankFileAccount;
use App\Modules\Employees\Models\Employee;
use App\Support\Contracts\PayableDocument;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;

class Payment extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_EXPORTED = 'exported';

    public const STATUS_PAID = 'paid';

    public const RTGS_THRESHOLD = 1000000;

    /** TransactionType code that marks a payroll transfer (TransactionTypeSeeder). */
    public const SALARY_TRANSACTION_CODE = 'salary';

    protected $fillable = [
        'payable_type', 'payable_id', 'transaction_type_id', 'company_bank_account_id',
        'payslip_id', 'subject_review', 'subject_review_reason', 'subject_reviewed_at', 'beneficiary_subscription_id', 'period',
        'amount', 'reference', 'details', 'value_date',
        'payment_type', 'status', 'journal_entry_id',
        'batch_reference', 'released_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'value_date' => 'date',
        'period' => 'date',
        'released_at' => 'datetime',
    ];

    /**
     * Not yet sent to the bank. What a batch is built from, and what keeps a
     * released payment out of the next one.
     */
    public function scopeUnreleased(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_DRAFT, self::STATUS_APPROVED]);
    }

    public function scopeInBatch(Builder $query, string $reference): Builder
    {
        return $query->where('batch_reference', $reference);
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }

    /**
     * May this payment go in a batch?
     *
     * A salary is only releasable once the employee has accepted the payslip it
     * pays: the acknowledgement is the point of the review step, and paying an
     * unacknowledged figure is the thing it exists to prevent. Payments with no
     * payslip behind them — a supplier, a petty cash top-up — have nobody to
     * acknowledge them and are releasable as soon as they exist.
     *
     * **Or once payroll has answered the objection.** A rejection used to be a dead end for the money: the
     * payslip refuses a second review, so an employee who objected to a payslip that turned out to be right
     * left their own salary unreleasable from any screen. `Payslip::resolveObjection()` is the way out — an
     * objection somebody has replied to, closed on the record — and `overridden` is what it leaves here.
     */
    public function isReleasable(): bool
    {
        if ($this->isReleased() || ! in_array($this->status, [self::STATUS_DRAFT, self::STATUS_APPROVED], true)) {
            return false;
        }

        if ($this->accountProblem() === BankFileAccount::PROBLEM_OWN_BANK_IBAN_ONLY) {
            return false;
        }

        // Read off this row, not off the payslip.
        //
        // A salary may not be released until the employee has accepted their payslip, and that used to be
        // answered by loading `payslip->employee_review` — the last thing that made Accounting depend on
        // Payroll for a *rule*. The decision is now copied here when it is made
        // (Payroll\Listeners\CopyReviewOntoPayment) and backfilled for everything that came before, so a
        // company can license Accounting without Payroll and this still answers.
        //
        // Null means nothing is waiting: a supplier payment, a rent transfer, and every payment that has
        // no payslip behind it. That is the same thing the old `$payslip === null` branch meant.
        return $this->subject_review === null
            || in_array($this->subject_review, [self::REVIEW_ACCEPTED, self::REVIEW_OVERRIDDEN], true);
    }

    /**
     * What is wrong with the identifier this payment would be sent on, if anything.
     *
     * @see BankFileAccount for the two cases and why only one of them stops a release
     */
    public function accountProblem(): ?string
    {
        // Explicitly loaded: this is asked of payments from a batch, a table row, a service and a
        // test, and only some of those eager-load the morph. Even the `! $this->payable` check below
        // would trigger the load. Where this is asked over a list — the bank file page — the query
        // eager-loads `payable` and this is then free.
        $this->loadMissing('payable');

        if (! $this->payable) {
            return null;
        }

        return $this->beneficiaryDetails()['account_problem'] ?? null;
    }

    /**
     * Why a held row is held, as a category rather than a sentence.
     *
     * The sentence below names the batch or quotes a rejection, which is right against one
     * row and useless for grouping — "Released in SAL-2026-07-B1" and "…-B2" are the same
     * fact twice. A summary line that groups by this can state what is actually true of each
     * group instead of asserting one reason for all of them, which is how a screen came to
     * say "held back until accepted" about payments that had already gone out.
     */
    public const BLOCK_RELEASED = 'released';

    public const BLOCK_STATUS = 'status';

    /**
     * The review states a payment's subject can be in.
     *
     * Deliberately Accounting's own constants with Accounting's own values, rather than a reference to
     * `Payslip::REVIEW_*`. The strings match because the column is a copy of that one — the listener that
     * writes it is `Payroll\Listeners\CopyReviewOntoPayment`, and PaymentReleaseGateTest asserts the two
     * vocabularies have not drifted. What this buys is that Accounting can be read, and packaged, without
     * Payroll on disk. See docs/module-packaging-plan.md §8 Group C.
     */
    public const REVIEW_ACCEPTED = 'accepted';

    public const REVIEW_REJECTED = 'rejected';

    /**
     * Rejected, then answered by payroll — and releasable again.
     *
     * The employee's objection stands on the record; what changed is that somebody holding `PayslipUpdate`
     * read it, replied in writing and took responsibility for paying anyway. Treated here exactly as an
     * acceptance is, and named differently for the same reason it is named differently on the payslip: this
     * salary went out over an objection, and a report that showed it as "accepted" would lose the one fact
     * anybody would later ask about.
     */
    public const REVIEW_OVERRIDDEN = 'overridden';

    public const BLOCK_REJECTED = 'rejected';

    public const BLOCK_UNACCEPTED = 'unaccepted';

    /** @var array<string, string> */
    public const BLOCK_LABELS = [
        self::BLOCK_RELEASED => 'Already released in an earlier batch',
        self::BLOCK_STATUS => 'No longer releasable',
        self::BLOCK_REJECTED => 'Rejected by the employee',
        self::BLOCK_UNACCEPTED => 'Held back until accepted',
        BankFileAccount::PROBLEM_OWN_BANK_IBAN_ONLY => 'Wrong kind of account number on file',
    ];

    public function releaseBlockedCategory(): ?string
    {
        if ($this->isReleasable()) {
            return null;
        }

        if ($this->isReleased()) {
            return self::BLOCK_RELEASED;
        }

        if (! in_array($this->status, [self::STATUS_DRAFT, self::STATUS_APPROVED], true)) {
            return self::BLOCK_STATUS;
        }

        if ($this->accountProblem() === BankFileAccount::PROBLEM_OWN_BANK_IBAN_ONLY) {
            return BankFileAccount::PROBLEM_OWN_BANK_IBAN_ONLY;
        }

        return $this->subject_review === self::REVIEW_REJECTED
            ? self::BLOCK_REJECTED
            : self::BLOCK_UNACCEPTED;
    }

    public static function blockLabel(?string $category): string
    {
        return self::BLOCK_LABELS[$category] ?? 'Held back';
    }

    /**
     * Why it cannot go out, in words, for a row the user can see but not select.
     */
    public function releaseBlockedReason(): ?string
    {
        if ($this->isReleasable()) {
            return null;
        }

        if ($this->isReleased()) {
            return 'Released in '.($this->batch_reference ?: 'an earlier batch');
        }

        if (! in_array($this->status, [self::STATUS_DRAFT, self::STATUS_APPROVED], true)) {
            return 'Already '.$this->status;
        }

        if ($this->accountProblem() === BankFileAccount::PROBLEM_OWN_BANK_IBAN_ONLY) {
            return 'They bank with us, so this needs their account number — an IBAN is what we '
                .'send to other banks. Add it on their record.';
        }

        return match ($this->subject_review) {
            self::REVIEW_REJECTED => 'Employee rejected the payslip'
                .($this->subject_review_reason ? ': '.$this->subject_review_reason : ''),
            default => 'Employee has not accepted the payslip yet',
        };
    }

    public function payable()
    {
        return $this->morphTo();
    }

    public function transactionType()
    {
        return $this->belongsTo(TransactionType::class);
    }

    public function companyBankAccount()
    {
        return $this->belongsTo(CompanyBankAccount::class);
    }

    public function subscription()
    {
        return $this->belongsTo(BeneficiarySubscription::class, 'beneficiary_subscription_id');
    }

    /*
     * `payslip()` is registered by Payroll, not declared here.
     *
     * `payslip_id` stays as a column — it is how somebody finds the payslip a salary came from, and what
     * the migration's backfill read. What went is Accounting naming a Payslip: the relation is added at
     * boot by PayrollServiceProvider through `Model::resolveRelationUsing()`, so `$payment->payslip`,
     * `with('payslip')` and `whereHas('payslip', ...)` all still work, and none of them exists when
     * Payroll is not installed — where there are no payslips to point at anyway.
     *
     * The release rule that used to read through this relation does not any more; see isReleasable().
     */

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Tax withheld from this payment, if any — `docs/erpnext-gap-plan.md` Phase 4.
     *
     * `hasOne`, because the deduction is one per payment and the database says so.
     */
    public function withholdingDeduction()
    {
        return $this->hasOne(WithholdingDeduction::class);
    }

    /**
     * What actually leaves the bank: the amount, less anything withheld at source.
     *
     * `amount` stays the gross, because that is what the company owes and what the journal entry debits.
     * The bank file has to carry the net — the transfer is the payment less the tax the company keeps back
     * to remit — and a file that paid the gross would leave the cash account disagreeing with the bank
     * statement by exactly the withheld figure. Every payment without a deduction returns `amount`
     * unchanged, which is all of them until a company assigns a withholding section to a supplier.
     */
    public function transferAmount(): float
    {
        return round((float) $this->amount - (float) ($this->withholdingDeduction?->amount ?? 0), 2);
    }

    /**
     * The iPayments Payment Type for this transaction:
     * explicit override → RTGS above threshold → PAY for an employee salary →
     * BT when the beneficiary banks with the debiting bank → beneficiary
     * default → IBFT.
     */
    public function resolvedPaymentType(): string
    {
        if ($this->payment_type) {
            return $this->payment_type;
        }

        // Above the threshold the bank requires RTGS regardless of what the
        // payment is for, so this outranks the salary rule below: a high-value
        // salary settles as RTGS, not PAY.
        if ((float) $this->amount >= self::RTGS_THRESHOLD) {
            return 'RTGS';
        }

        // Otherwise a salary transfer is its own payment type, outranking the
        // intra-bank and beneficiary-default routing below.
        if ($this->isEmployeeSalary()) {
            return (string) (setting('ipayments')['salary_payment_type'] ?? 'PAY');
        }

        $payee = $this->payee();
        $payeeBankId = $payee?->bank_id;

        $debitBankId = $this->companyBankAccount?->bank_id;

        if ($payeeBankId && $debitBankId && $payeeBankId === $debitBankId) {
            return 'BT';
        }

        if ($payee instanceof Beneficiary && $payee->payment_type) {
            return $payee->payment_type;
        }

        return 'IBFT';
    }

    /**
     * The party the bank file pays: the payable itself, or for a document — a supplier bill — whoever the
     * document names. See App\Support\Contracts\PayableDocument.
     */
    public function payee(): ?EloquentModel
    {
        $payable = $this->loadMissing('payable')->payable;

        return $payable instanceof PayableDocument ? $payable->payee() : $payable;
    }

    /**
     * Whether this payment is an employee salary transfer.
     *
     * Settling a payslip is decisive. Otherwise it must be a payment to an
     * employee under the salary transaction type — an employee can also be paid
     * an advance or a reimbursement, and those are ordinary transfers, not
     * payroll, so "the payee is an employee" alone is not enough.
     */
    public function isEmployeeSalary(): bool
    {
        if ($this->payslip_id) {
            return true;
        }

        if (! $this->payable instanceof Employee) {
            return false;
        }

        return $this->transactionType?->code === self::SALARY_TRANSACTION_CODE;
    }

    /**
     * Beneficiary-side columns for the bank file, from either an
     * Employee or a Beneficiary payable.
     */
    public function beneficiaryDetails(): array
    {
        // See accountProblem(): same morph, same reason, and this one is also called directly.
        //
        // `bank` too, on whichever side the morph landed — both an Employee and a Beneficiary have
        // one, and the account resolution below reads it. Over a list this is already loaded by the
        // caller's morphWith (see SalaryBankFile) and this line does nothing. For a bill the party is
        // the supplier it names, which the morph cannot preload — one query per bill in a file.
        $payable = $this->payee();

        // `bank` on either side, and `user` on the employee side for the name and email this puts in
        // the file. Guarded by type rather than loaded blindly: a Beneficiary has no user, and asking
        // for one throws a RelationNotFoundException rather than returning null.
        $payable?->loadMissing($payable instanceof Employee ? ['bank', 'user'] : ['bank']);

        if ($payable instanceof Employee) {
            $account = BankFileAccount::resolve(
                $payable->iban_no,
                $payable->bank_account_no,
                $payable->bank,
                $payable->bank_short_code,
                $payable->bank_name ?? null,
            );

            return [
                'name' => $payable->user->name ?? $payable->employee_id,
                'account' => $account['value'],
                'account_kind' => $account['kind'],
                'account_problem' => $account['problem'],
                'bank_code' => $payable->bank?->bank_code ?? $payable->bank_code ?? '',
                'bank_name' => $payable->bank?->bank_name ?? $payable->bank_name ?? '',
                'bank_short_code' => $payable->bank?->bank_short_code ?? $payable->bank_short_code ?? '',
                'email' => $payable->user->email ?? '',
                'id_number' => $payable->nic ?? '',
                'id_type' => $payable->nic ? 'CNIC' : '',
                'phone' => $payable->phone ?? '',
                'address_1' => $payable->address_line_1 ?? '',
                'address_2' => $payable->address_line_2 ?? '',
            ];
        }

        $account = BankFileAccount::resolve(
            $payable->iban ?? null,
            $payable->account_no ?? null,
            $payable->bank ?? null,
        );

        return [
            'name' => $payable->name ?? '',
            'account' => $account['value'],
            'account_kind' => $account['kind'],
            'account_problem' => $account['problem'],
            'bank_code' => $payable->bank?->bank_code ?? '',
            'bank_name' => $payable->bank?->bank_name ?? '',
            'bank_short_code' => $payable->bank?->bank_short_code ?? '',
            'email' => $payable->email ?? '',
            'id_number' => $payable->id_number ?? '',
            'id_type' => $payable->id_number ? ($payable->id_type ?? 'CNIC') : '',
            'phone' => $payable->phone ?? '',
            'address_1' => $payable->address_line_1 ?? '',
            'address_2' => $payable->address_line_2 ?? '',
        ];
    }
}
