<?php

namespace App\Modules\Leave\Services;

use App\Modules\Core\Models\User;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Notifications\LeaveRequestDecided;
use App\Modules\Leave\Notifications\LeaveRequestSubmitted;
use App\Support\TenantTransaction;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * Submitting, deciding and cancelling leave.
 *
 * The lifecycle is ExpenseClaim's and the self-approval handling is
 * JournalEntryService's — both deliberately, because both are built and tested here
 * and a third way of doing either would be a third thing to get wrong.
 *
 * The one thing this adds to that pattern is that approval *generates* something: the
 * leave_days rows, from the holiday calendar and the sandwich setting as they stand
 * at that moment. Those inputs are read once here and never again, which is the whole
 * of docs/hrms-plan.md §4.7's rule — a setting decides what happens next, never what
 * already happened.
 */
class LeaveRequestService
{
    public function __construct(
        private readonly LeaveDayGenerator $generator,
        private readonly LeaveApprovalRule $rule,
        private readonly LeaveBalance $balance,
        private readonly LeaveApprovalChain $chain,
    ) {}

    /**
     * File a request and tell whoever may decide it.
     *
     * Notice is checked here rather than in the form so that every route in — panel,
     * API, importer — gets the same answer. Whether short notice blocks or merely
     * warns is leave.min_notice_enforced, and it defaults to warn: a system that
     * refuses to record leave somebody has already taken helps nobody.
     */
    public function submit(LeaveRequest $request, User $submitter): LeaveRequest
    {
        $request->forceFill([
            'status' => LeaveRequest::STATUS_PENDING,
            'submitted_by' => $submitter->getKey(),
        ]);

        // Planned before anything is written, so a request that could never be
        // approved is never filed. plan() itself throws on a malformed half day or a
        // reversed range; an empty plan is refused here rather than there, because
        // planning legitimately answers "nothing" for a caller only asking the cost.
        $planned = $this->generator->plan($request);

        if ($planned === []) {
            throw new InvalidArgumentException(LeaveDayGenerator::NO_WORKING_DAYS);
        }

        $this->assertNoticeGiven($request);
        $this->assertNoOverlap($request);

        $request->save();

        // Written now, not at approval, so the form and the approver's queue can
        // show what the request will cost. Regenerated at approval from the
        // calendar as it stands then, which is the figure that counts.
        $request->forceFill(['days' => array_sum(array_column($planned, 'portion'))])->saveQuietly();

        $this->notifyApprovers($request);

        return $request->refresh();
    }

    /**
     * Approve it, generating the days it consumes.
     *
     * Both in one transaction: a request marked approved with no days is a leave
     * nobody has taken and a balance that never moves, which is worse than a failed
     * approval somebody can retry.
     */
    public function approve(LeaveRequest $request, User $approver): LeaveRequest
    {
        $this->assertPending($request, 'approved');

        // Segregation of duties, unless the company has said it has nobody to
        // segregate with. Unlike the ledger, the dead end here is the reporting
        // tree rather than the company: the employee at the top has no manager, so
        // with the rule on their leave needs somebody privileged to decide it.
        $selfApproved = $request->belongsToUser($approver);

        if ($selfApproved && $this->rule->isRequired()) {
            throw new InvalidArgumentException(
                'Leave cannot be approved by the person who filed it. Route it to their manager, '
                .'or turn off the second-approver requirement for this company.'
            );
        }

        // Multi-step: the roles the company named come after the first approval, in order — docs/hrms-plan.md
        // §11, LeaveApprovalChain. A step that is not the last records itself and tells the next role; only
        // the last one below generates the days and tells the employee.
        $step = $this->chain->nextStep($request);
        $role = $this->chain->roleForStep($step);

        if ($role !== null && ! $approver->hasRole($role)) {
            throw new InvalidArgumentException("This request is waiting for somebody holding the {$role} role to approve it.");
        }

        if (! $this->chain->isFinal($step)) {
            $request->approvals()->create([
                'step' => $step,
                'role' => $role,
                'approved_by' => $approver->getKey(),
                'approved_at' => now(),
            ]);

            activity('LeaveRequest')
                ->performedOn($request)
                ->causedBy($approver)
                ->event('approval_step')
                ->withProperties(['step' => $step, 'role' => $role, 'awaiting' => $this->chain->roleForStep($step + 1)])
                ->log("Leave request approval step {$step} given; waiting for ".$this->chain->roleForStep($step + 1));

            $this->notifyRole($request, $this->chain->roleForStep($step + 1));

            return $request->refresh();
        }

        TenantTransaction::run(function () use ($request, $approver, $step, $role): void {
            // The last step of a chain is recorded with the rest, so the table reads as the whole story.
            if ($step > 1) {
                $request->approvals()->create([
                    'step' => $step,
                    'role' => $role,
                    'approved_by' => $approver->getKey(),
                    'approved_at' => now(),
                ]);
            }

            $request->update([
                'status' => LeaveRequest::STATUS_APPROVED,
                'decided_by' => $approver->getKey(),
                'decided_at' => now(),
                'refusal_reason' => null,
            ]);

            // Reads the holiday calendar, leave.sandwich_rule and leave.weekend_days
            // as they stand now, and stamps what it used. Never run again for this
            // request.
            $this->generator->generate($request);
        });

        activity('LeaveRequest')
            ->performedOn($request)
            ->causedBy($approver)
            ->event('approved')
            // A waived control that leaves no trace is worse than no control: the
            // point of turning the setting off is to keep working, not to make the
            // record look like two people checked it.
            ->withProperties(array_filter([
                'days' => (float) $request->fresh()->days,
                'self_approved' => $selfApproved ?: null,
            ]))
            ->log('Leave request approved'.($selfApproved
                ? ' by the person who filed it (no second approver required for this company)'
                : ''));

        $this->notifySubmitter($request->refresh());

        return $request;
    }

    /** A refusal carries its reason — being told no without being told why is what approval exists to answer. */
    public function refuse(LeaveRequest $request, User $approver, string $reason): LeaveRequest
    {
        $this->assertPending($request, 'refused');

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A refusal needs a reason.');
        }

        if ($request->belongsToUser($approver) && $this->rule->isRequired()) {
            throw new InvalidArgumentException('Leave cannot be decided by the person who filed it.');
        }

        $request->update([
            'status' => LeaveRequest::STATUS_REFUSED,
            'decided_by' => $approver->getKey(),
            'decided_at' => now(),
            'refusal_reason' => trim($reason),
        ]);

        $this->notifySubmitter($request);

        return $request;
    }

    /**
     * Withdraw a request, giving its days back.
     *
     * Allowed after approval as well as before it, because plans change and leave
     * that was approved and not taken must not go on costing somebody their balance.
     * The days rows go with it — the balance sums leave_days, so leaving them would
     * keep charging for leave nobody took.
     *
     * What is deliberately NOT here is any interaction with a settled payroll month.
     * Nothing in this phase writes to a payslip, so there is no settled figure to
     * contradict; when the payroll join lands, this is the method that has to learn
     * about PayrollRun locks.
     */
    public function cancel(LeaveRequest $request, User $user): LeaveRequest
    {
        if (in_array($request->status, [LeaveRequest::STATUS_REFUSED, LeaveRequest::STATUS_CANCELLED], true)) {
            throw new InvalidArgumentException("This request is already {$request->status}.");
        }

        TenantTransaction::run(function () use ($request, $user): void {
            $request->days()->delete();

            $request->update([
                'status' => LeaveRequest::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user->getKey(),
                'days' => 0,
            ]);
        });

        return $request;
    }

    /**
     * What this request would leave the employee with, or null for an uncounted type.
     *
     * Not enforced as a block, and that is a decision rather than an omission. An
     * employee who is over their balance has usually taken leave the company agreed
     * to; refusing to record it makes the register wrong rather than the leave
     * un-taken. The approver sees the figure and decides — the same position §4.2
     * takes on overtime caps and §5 on SLA breaches.
     */
    public function balanceAfter(LeaveRequest $request): ?float
    {
        if (! $request->employee || ! $request->leaveType) {
            return null;
        }

        $breakdown = $this->balance->for($request->employee, $request->leaveType, $request->from_date);

        if (! $breakdown) {
            return null;
        }

        $cost = $request->isApproved()
            ? 0.0
            : array_sum(array_column($this->generator->plan($request), 'portion'));

        return round($breakdown->remaining() - $cost, 1);
    }

    private function assertPending(LeaveRequest $request, string $verb): void
    {
        if (! $request->isPending()) {
            throw new InvalidArgumentException(
                "This request is already {$request->status} and cannot be {$verb}."
            );
        }
    }

    private function assertNoticeGiven(LeaveRequest $request): void
    {
        $notice = (int) ($request->leaveType->min_notice_days ?? 0);

        if ($notice <= 0 || ! setting('leave.min_notice_enforced')) {
            return;
        }

        if (now()->startOfDay()->diffInDays($request->from_date, false) < $notice) {
            throw new InvalidArgumentException(
                "{$request->leaveType->label} needs {$notice} days' notice."
            );
        }
    }

    /**
     * Two requests cannot claim the same day.
     *
     * Enforced rather than warned about, because the alternative is a balance
     * charged twice for one day off — and unlike an over-drawn balance, that is
     * arithmetic nobody agreed to. Refused and cancelled requests are ignored: they
     * consumed nothing.
     */
    private function assertNoOverlap(LeaveRequest $request): void
    {
        $clash = LeaveRequest::query()
            ->where('employee_id', $request->employee_id)
            ->whereIn('status', [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED])
            ->when($request->exists, fn ($query) => $query->whereKeyNot($request->getKey()))
            ->overlapping(
                $request->from_date->toDateString(),
                $request->to_date->toDateString(),
            )
            ->first();

        if ($clash) {
            throw new InvalidArgumentException(
                'This overlaps leave already requested from '
                .$clash->from_date->format('d M Y').' to '.$clash->to_date->format('d M Y').'.'
            );
        }
    }

    /** Never to the person who filed it: the point of an approver is that it is somebody else. */
    private function notifyApprovers(LeaveRequest $request): void
    {
        $approvers = User::holdingPermission(LeaveRequest::APPROVE_PERMISSION)
            ->where('id', '!=', $request->submitted_by)
            ->where('status', 1)
            ->get();

        Notification::send($approvers, new LeaveRequestSubmitted($request));
    }

    /** The holders of the role the chain now waits for — never the person who filed it. */
    private function notifyRole(LeaveRequest $request, ?string $role): void
    {
        if ($role === null) {
            return;
        }

        $holders = User::role($role)
            ->where('id', '!=', $request->submitted_by)
            ->where('status', 1)
            ->get();

        Notification::send($holders, new LeaveRequestSubmitted($request, awaiting: $role));
    }

    private function notifySubmitter(LeaveRequest $request): void
    {
        $submitter = User::query()->acrossCompanies()->find($request->submitted_by);

        if ($submitter && (int) $submitter->status === 1) {
            Notification::send($submitter, new LeaveRequestDecided($request));
        }
    }
}
