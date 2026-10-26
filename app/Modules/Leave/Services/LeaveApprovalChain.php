<?php

namespace App\Modules\Leave\Services;

use App\Modules\Core\Models\User;
use App\Modules\Leave\Models\LeaveRequest;

/**
 * Multi-step approval: manager, then the roles the company names, in order — docs/hrms-plan.md §11.
 *
 * `leave.approval_chain` is a list of role names, empty by default. Empty, nothing here changes: one
 * approval by anybody who may decide, as it always was. Set to `['HR', 'CEO']`, the first approval is
 * still anybody who may decide (the manager, in practice — row scoping is what puts the request in front
 * of them), and the request then waits for somebody holding HR, then somebody holding CEO; only the last
 * approval generates the days and notifies the employee. Each step is a row in `leave_approvals`.
 *
 * **Not a generic approval engine**, which §11 forbids: the vocabulary, the segregation rule and the
 * notifications are leave's own, and the chain is three small questions — which step is next, whose
 * role it needs, and is this the last.
 */
class LeaveApprovalChain
{
    public const SETTING_KEY = 'leave.approval_chain';

    /** @return array<int, string> the roles after the first approval, in order */
    public function roles(): array
    {
        return array_values(array_filter(array_map('strval', (array) setting(self::SETTING_KEY, [])), fn (string $role): bool => $role !== ''));
    }

    /** The step the next approval would be: one more than the steps already taken. */
    public function nextStep(LeaveRequest $request): int
    {
        return (int) $request->approvals()->max('step') + 1;
    }

    /** The role the given step needs, or null for the first step, which anybody who may decide takes. */
    public function roleForStep(int $step): ?string
    {
        return $step <= 1 ? null : ($this->roles()[$step - 2] ?? null);
    }

    /** Whether the given step is the one that approves the request. */
    public function isFinal(int $step): bool
    {
        return $step >= count($this->roles()) + 1;
    }

    /** The role the request is waiting for now, or null when its next approval is anybody's. */
    public function awaitingRole(LeaveRequest $request): ?string
    {
        return $this->roleForStep($this->nextStep($request));
    }

    /** May this user take the request's next step? The first is anybody's; the rest need the role. */
    public function userMayTakeNextStep(User $user, LeaveRequest $request): bool
    {
        $role = $this->awaitingRole($request);

        return $role === null || $user->hasRole($role);
    }

    /** "waiting for HR, after the manager" — for the list, or null in a single-step company. */
    public function awaitingLabel(LeaveRequest $request): ?string
    {
        if (! $request->isPending() || $this->roles() === []) {
            return null;
        }

        $taken = $request->approvals()->count();
        $role = $this->awaitingRole($request);

        if ($role === null) {
            return 'waiting for the first approval, then '.implode(', then ', $this->roles());
        }

        return "waiting for {$role} ({$taken} of ".(count($this->roles()) + 1).' approvals given)';
    }
}
