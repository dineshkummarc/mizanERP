<?php

namespace App\Modules\Leave\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveApprovalChain;
use App\Modules\Leave\Services\LeaveApprovalRule;

class LeaveRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('LeaveRequestView');
    }

    public function view(User $user, LeaveRequest $request): bool
    {
        return $user->hasPermissionTo('LeaveRequestView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('LeaveRequestCreate');
    }

    /**
     * Only while nobody has decided it.
     *
     * An approved request has generated leave_days that a balance has already been
     * read against; editing the range afterwards would move days somebody has
     * planned around without anyone agreeing to it. Correcting an approved request
     * means cancelling it and filing again, which leaves both facts on the record.
     */
    public function update(User $user, LeaveRequest $request): bool
    {
        return $user->hasPermissionTo('LeaveRequestUpdate') && $request->isPending();
    }

    public function delete(User $user, LeaveRequest $request): bool
    {
        return $user->hasPermissionTo('LeaveRequestDelete') && $request->isPending();
    }

    /**
     * Deciding is its own permission: filing leave is not approving it.
     *
     * The self-approval check is here *and* in LeaveRequestService, and that is
     * deliberate rather than duplication. This one hides the button; the service one
     * holds for every route in — the API, an importer, tinker — and for
     * Administrators and super admins, who pass every policy check and would
     * otherwise slip past a rule that has to apply to everyone.
     */
    public function decide(User $user, LeaveRequest $request): bool
    {
        if (! $user->hasPermissionTo(LeaveRequest::APPROVE_PERMISSION) || ! $request->isPending()) {
            return false;
        }

        if ($request->belongsToUser($user) && ! app(LeaveApprovalRule::class)->allowsSelfApproval()) {
            return false;
        }

        // Past the first approval, the step belongs to whoever holds the role the chain names.
        return app(LeaveApprovalChain::class)->userMayTakeNextStep($user, $request);
    }

    /**
     * Withdrawing is not deciding.
     *
     * The person whose leave it is may cancel their own, approved or not, because
     * plans change and leave nobody took must not go on costing them a balance.
     * Anybody else needs the approve permission.
     */
    public function cancel(User $user, LeaveRequest $request): bool
    {
        if (in_array($request->status, [LeaveRequest::STATUS_REFUSED, LeaveRequest::STATUS_CANCELLED], true)) {
            return false;
        }

        return $request->belongsToUser($user)
            || $user->hasPermissionTo(LeaveRequest::APPROVE_PERMISSION);
    }
}
