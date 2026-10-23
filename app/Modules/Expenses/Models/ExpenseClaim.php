<?php

namespace App\Modules\Expenses\Models;

use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Expenses\Notifications\ExpenseClaimDecided;
use App\Modules\Expenses\Notifications\ExpenseClaimSubmitted;
use App\Modules\Expenses\Services\ExpenseClaimBankPayment;
use App\Modules\Expenses\Services\ExpenseClaimPosting;
use App\Modules\Payroll\Models\Payslip;
use App\Support\Contracts\PayableDocument;
use App\Support\TenantTransaction;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * An expense an employee paid for and is owed back.
 *
 * The workflow is EmployeeChangeRequest's, deliberately: submit, notify whoever may
 * approve, approve or refuse *with a reason*. That pattern is built and tested on
 * another subject, and a second way of doing the same thing would be a second thing
 * to get wrong.
 */
class ExpenseClaim extends Model implements PayableDocument
{
    use Auditable;

    // ─── PayableDocument — paid by bank transfer, docs/bexio-gap-plan.md §3.6 ───

    /** The bank file pays the employee, on the bank details of their record. */
    public function payee(): ?EloquentModel
    {
        // Loaded explicitly: a list of payments reaches this through the morph, where the lazy-load guard
        // would otherwise trip on the second row (`user` is what display_label and the file read).
        return $this->loadMissing('employee.user')->employee;
    }

    public function paymentLabel(): string
    {
        return "Expense claim #{$this->getKey()}";
    }

    /** Approving the payment clears the liability approval booked; see ExpenseClaimBankPayment::settle(). */
    public function settleByPayment(EloquentModel $payment): ?EloquentModel
    {
        return app(ExpenseClaimBankPayment::class)->settle($this, $payment);
    }

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REFUSED = 'refused';

    /** Approved and paid — with a payslip, or on its own. */
    public const STATUS_SETTLED = 'settled';

    /** Who may decide a claim. */
    public const APPROVE_PERMISSION = 'ExpenseClaimApprove';

    protected $fillable = [
        'employee_id', 'transaction_type_id', 'claimed_on', 'description', 'amount',
        'notes', 'receipt_path', 'status', 'submitted_by', 'decided_by', 'decided_at',
        'refusal_reason', 'payslip_id', 'payment_id', 'journal_entry_id',
    ];

    protected $casts = [
        'claimed_on' => 'date',
        'amount' => 'decimal:2',
        'decided_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected static function booted(): void
    {
        // The accrual goes with the claim — docs/bexio-gap-plan.md Phase 3, item 13. Reversed rather than
        // deleted, so the ledger says the liability existed and was taken back.
        static::deleting(function (self $claim): void {
            app(ExpenseClaimPosting::class)->reverse($claim);
        });

        static::created(function (self $claim): void {
            // Never to the person who submitted it, even if they hold the permission:
            // the point of an approver is that it is somebody else.
            $approvers = User::holdingPermission(self::APPROVE_PERMISSION)
                ->where('id', '!=', $claim->submitted_by)
                ->where('status', 1)
                ->get();

            Notification::send($approvers, new ExpenseClaimSubmitted($claim));
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function transactionType(): BelongsTo
    {
        return $this->belongsTo(TransactionType::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /** Approved and not yet paid: what the company owes this employee back, however it will be paid. */
    public function scopeAwaitingSettlement(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Owed, and payroll's to pay: a claim with a bank payment raised for it is spoken for, even while
     * that payment is still a draft — a payslip reimbursing it too would pay the person twice.
     */
    public function scopeReimbursableByPayroll(Builder $query): Builder
    {
        return $query->awaitingSettlement()->whereNull('payment_id');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isSettled(): bool
    {
        return $this->status === self::STATUS_SETTLED;
    }

    /**
     * Approve, and book what is now owed — docs/bexio-gap-plan.md Phase 3, item 13.
     *
     * One transaction over the status and the accrual: a claim approved whose posting then failed would
     * be a liability the report shows and the ledger does not, which is the hole this closes.
     */
    public function approve(User $approver): self
    {
        $this->assertPending('approved');
        $this->assertNotOwnClaim($approver);

        TenantTransaction::run(function () use ($approver): void {
            $this->update([
                'status' => self::STATUS_APPROVED,
                'decided_by' => $approver->getKey(),
                'decided_at' => now(),
                'refusal_reason' => null,
            ]);

            app(ExpenseClaimPosting::class)->accrue($this);
        });

        $this->notifySubmitter();

        return $this;
    }

    /** The accrual approval booked, where Accounting is licensed — ExpenseClaimPosting. */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * A refusal carries its reason. Being told no without being told why is the
     * complaint the whole approval step exists to answer.
     */
    public function refuse(User $approver, string $reason): self
    {
        $this->assertPending('refused');
        $this->assertNotOwnClaim($approver);

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A refusal needs a reason.');
        }

        $this->update([
            'status' => self::STATUS_REFUSED,
            'decided_by' => $approver->getKey(),
            'decided_at' => now(),
            'refusal_reason' => trim($reason),
        ]);

        $this->notifySubmitter();

        return $this;
    }

    private function assertPending(string $verb): void
    {
        if (! $this->isPending()) {
            throw new InvalidArgumentException(
                "This claim is already {$this->status} and cannot be {$verb}."
            );
        }
    }

    /**
     * Enforced on the model rather than in the policy: Administrators and super
     * admins pass every policy check, so a rule that has to hold for everyone cannot
     * live in one — and "somebody else approves it" is the whole point.
     */
    private function assertNotOwnClaim(User $approver): void
    {
        if ($this->submitted_by === $approver->getKey()) {
            throw new InvalidArgumentException('A claim cannot be decided by the person who submitted it.');
        }
    }

    private function notifySubmitter(): void
    {
        $submitter = User::query()->acrossCompanies()->find($this->submitted_by);

        if ($submitter && (int) $submitter->status === 1) {
            Notification::send($submitter, new ExpenseClaimDecided($this));
        }
    }
}
