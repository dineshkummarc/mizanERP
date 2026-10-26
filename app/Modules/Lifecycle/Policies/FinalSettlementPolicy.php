<?php

namespace App\Modules\Lifecycle\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Lifecycle\Models\FinalSettlement;

class FinalSettlementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('SettlementView');
    }

    public function view(User $user, FinalSettlement $settlement): bool
    {
        if (! $user->hasPermissionTo('SettlementView')) {
            return false;
        }

        // A settlement manager sees all; a mere viewer sees their own reporting line — security audit finding 9.
        if (\App\Modules\Lifecycle\Filament\Resources\FinalSettlements\FinalSettlementResource::seesEverySettlement($user)) {
            return true;
        }

        return app(\App\Support\EmployeeAccess::class)->accessibleEmployeeIds($user)->contains($settlement->employee_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('SettlementCreate');
    }

    /** Only while it is a draft: an approved figure is what somebody committed to. */
    public function update(User $user, FinalSettlement $settlement): bool
    {
        return $user->hasPermissionTo('SettlementUpdate') && $settlement->isDraft();
    }

    /**
     * Approving is its own permission, and never for your own settlement.
     *
     * Checked here rather than only in a service because this is money leaving the
     * company to the person pressing the button — the one place in this application
     * where that is literally true.
     */
    public function approve(User $user, FinalSettlement $settlement): bool
    {
        return $user->hasPermissionTo('SettlementApprove')
            && $settlement->isDraft()
            && $settlement->employee?->user_id !== $user->getKey();
    }

    /**
     * Taking an approval back — the way out of an approved figure that is wrong.
     *
     * `FinalSettlementBuilder` has told people to "reopen it before rebuilding" since the module shipped,
     * and until now there was nothing to press. Same permission as approving, because it is the same
     * decision in reverse, and the same self-approval bar: reopening your own settlement is the first half
     * of agreeing your own figure.
     *
     * **Never once it is paid.** `approved` is a commitment; `paid` is money that has left through a
     * payslip or a payment, and reopening that would leave this record disagreeing with the ledger. The
     * payslip and payment columns are checked too rather than trusting the status alone — either one being
     * set means a settlement somebody has already acted on.
     */
    public function reopen(User $user, FinalSettlement $settlement): bool
    {
        return $user->hasPermissionTo('SettlementApprove')
            && $settlement->isApproved()
            && $settlement->payslip_id === null
            && $settlement->payment_id === null
            && $settlement->employee?->user_id !== $user->getKey();
    }

    public function delete(User $user, FinalSettlement $settlement): bool
    {
        return $user->hasPermissionTo('SettlementDelete') && $settlement->isDraft();
    }
}
