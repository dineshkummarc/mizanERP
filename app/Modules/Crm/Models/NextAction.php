<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * What happens next.
 *
 * **The feature that makes the difference between a CRM people use and a data-entry chore.**
 * Everything else in this module records the past; this is the only part that changes what
 * happens tomorrow, and the one rule §3 states is that *an open opportunity with no next
 * action is surfaced as a problem.*
 *
 * Separate from `activities` because the two are different in kind: a completed activity is
 * history and never changes, an action is a mutable intention with a due date, an assignee
 * and a snooze. One table would give every list query an
 * `is_done`-plus-`due`-plus-`occurred` filter that is wrong somewhere.
 */
class NextAction extends Model
{
    use Auditable;
    use StoresPlainDates;

    protected array $plainDates = ['due_on', 'snoozed_until', 'reminded_on'];

    protected $fillable = [
        'subject_type', 'subject_id', 'title', 'due_on', 'due_at',
        'assignee_employee_id', 'completed_at', 'completed_by', 'snoozed_until', 'reminded_on', 'created_by',
    ];

    protected $casts = [
        'due_on' => 'date',
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'snoozed_until' => 'date',
        // The due date the assignee was last told about — NextActionReminders.
        'reminded_on' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $action): void {
            $action->created_by ??= auth()->id();

            if ($action->assignee_employee_id === null && modules()->enabled('employees')) {
                $action->assignee_employee_id = Employee::where('user_id', auth()->id())->value('id');
            }
        });
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assignee_employee_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }

    /**
     * Open and due — snoozes respected.
     *
     * A snoozed action is not due, but the ORIGINAL `due_on` is kept rather than moved, so
     * "this has been snoozed four times" stays visible. That is usually the more useful
     * fact than the new date.
     */
    public function scopeDue(Builder $query, ?string $on = null): Builder
    {
        $on = $on ?: now()->toDateString();

        return $query->open()
            ->whereDate('due_on', '<=', $on)
            ->where(fn (Builder $q) => $q
                ->whereNull('snoozed_until')
                ->orWhereDate('snoozed_until', '<=', $on));
    }

    public function isOpen(): bool
    {
        return $this->completed_at === null;
    }

    public function isSnoozed(): bool
    {
        return $this->snoozed_until !== null && $this->snoozed_until->isFuture();
    }

    /** The date it is actually waiting on, which is the snooze when there is one. */
    public function effectiveDueOn(): \Illuminate\Support\Carbon
    {
        return $this->isSnoozed() ? $this->snoozed_until : $this->due_on;
    }
}
