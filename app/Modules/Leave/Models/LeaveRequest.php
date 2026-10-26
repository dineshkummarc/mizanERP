<?php

namespace App\Modules\Leave\Models;

use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Services\LeaveApprovalChain;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Somebody asking to be away.
 *
 * The status vocabulary is ExpenseClaim's, verbatim, because a second vocabulary for
 * the same idea is how one module ends up saying `rejected` where another says
 * `refused` — which four approval flows in this codebase already do
 * (docs/hrms-plan.md §11).
 *
 * Deciding a request lives in LeaveRequestService rather than on this model, which
 * is where it differs from ExpenseClaim. An approval here generates leave_days from
 * the holiday calendar and the sandwich setting, and writes an activity entry when a
 * self-approval was waived — three collaborators, which is a service. The model
 * keeps the vocabulary, the relations and the predicates the policy reads.
 */
class LeaveRequest extends Model
{
    use Auditable;
    use StoresPlainDates;

    /** Stored as plain dates so overlapping() compares like with like on either driver. */
    protected array $plainDates = ['from_date', 'to_date'];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REFUSED = 'refused';

    public const STATUS_CANCELLED = 'cancelled';

    /** Who may decide somebody else's leave. */
    public const APPROVE_PERMISSION = 'LeaveRequestApprove';

    public const HALF_FIRST = 'first';

    public const HALF_SECOND = 'second';

    protected $fillable = [
        'employee_id', 'leave_type_id', 'from_date', 'to_date', 'days',
        'is_half_day', 'half_day_period', 'reason', 'document_path', 'status',
        'submitted_by', 'decided_by', 'decided_at', 'refusal_reason',
        'cancelled_at', 'cancelled_by', 'sandwich_rule_applied',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'days' => 'decimal:1',
        'is_half_day' => 'boolean',
        'decided_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'sandwich_rule_applied' => 'boolean',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'days' => 0,
        'is_half_day' => false,
        'sandwich_rule_applied' => false,
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(LeaveDay::class, 'leave_request_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** The steps taken so far of a multi-step approval — LeaveApprovalChain. Empty in a single-step company. */
    public function approvals(): HasMany
    {
        return $this->hasMany(LeaveApproval::class)->orderBy('step');
    }

    /** "waiting for HR" while a chain is part-way through; null otherwise. */
    public function awaitingLabel(): ?string
    {
        return app(LeaveApprovalChain::class)->awaitingLabel($this);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /** Requests whose range overlaps a window — not merely those contained by it. */
    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query->whereDate('from_date', '<=', $to)->whereDate('to_date', '>=', $from);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Whether this request belongs to the user in front of us.
     *
     * Two ways it can, and both count. The obvious one is that they filed it. The
     * one worth checking separately is that it is *their* leave — an HR clerk may
     * file on somebody's behalf, and the employee then approving it is still the
     * self-approval the setting exists to prevent.
     */
    public function belongsToUser(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->submitted_by === $user->getKey()
            || ($this->employee?->user_id !== null && $this->employee->user_id === $user->getKey());
    }

    /**
     * The days this request consumed that fall inside a calendar month.
     *
     * The reason leave_days exists: a request from 28 January to 3 February has to
     * reach two payslips, and a `days` total cannot be split.
     */
    public function daysInMonth(int $year, int $month): float
    {
        return (float) $this->days()->inMonth($year, $month)->sum('portion');
    }
}
