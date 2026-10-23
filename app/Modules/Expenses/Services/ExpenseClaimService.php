<?php

namespace App\Modules\Expenses\Services;

use App\Modules\Expenses\Models\ExpenseClaim;
use App\Modules\Payroll\Models\Payslip;
use App\Support\Contracts\ReimbursableClaims;
use App\Support\PayslipSettlement;
use Illuminate\Support\Collection;

/**
 * Getting an approved claim paid.
 *
 * Reimbursement rides on the payslip, because that is where the money already
 * reached the employee: `payslips.expense_reimbursement` existed before claims did
 * and is already added to net pay and posted by PayrollPostingService. What was
 * missing was any record of what the figure was for.
 *
 * The shape is AdvanceService's, for the same reason: payroll recalculates a payslip
 * on every save, so anything attached to one has to be idempotent per payslip or the
 * employee is reimbursed twice.
 *
 * **Payroll asks; it does not name this class.** `expenses` requires `payroll`, so Payroll reaching back here
 * was a two-cycle between a module and the one it declares — the last one in the application, with the
 * identical one through Advances. Bound to `App\Support\Contracts\ReimbursableClaims` from
 * `ExpensesServiceProvider`; see docs/module-packaging-plan.md §11. The licence guard lives here rather than
 * in Payroll's model hooks, as it does in `PayrollRunPeriodLock`.
 */
class ExpenseClaimService implements ReimbursableClaims
{
    /**
     * What this employee is owed back, and not yet paid.
     *
     * Claims already settled against *this* payslip count, because the payslip is
     * being recalculated and its own figure must not shrink out from under it — the
     * same exclusion AdvanceService makes for a payslip's own recovery.
     */
    public function reimbursableFor(int|string $employeeId, int|string|null $includingPayslipId = null): float
    {
        if (! modules()->enabled('expenses')) {
            return 0.0;
        }

        return round((float) ExpenseClaim::query()
            ->where('employee_id', $employeeId)
            ->where(function ($query) use ($includingPayslipId): void {
                // Not a claim with a bank payment raised for it — that one is being paid the other way.
                $query->where(fn ($owed) => $owed->reimbursableByPayroll())
                    ->when(
                        $includingPayslipId,
                        fn ($q) => $q->orWhere(fn ($own) => $own
                            ->where('status', ExpenseClaim::STATUS_SETTLED)
                            ->where('payslip_id', $includingPayslipId)),
                    );
            })
            ->sum('amount'), 2);
    }

    /**
     * @return Collection<int, ExpenseClaim>
     */
    public function awaitingSettlementFor(int $employeeId): Collection
    {
        return ExpenseClaim::reimbursableByPayroll()
            ->where('employee_id', $employeeId)
            ->orderBy('claimed_on')
            ->get();
    }

    /**
     * Attach the claims this payslip reimburses, and release any it no longer does.
     *
     * Called on every payslip save, so it has to converge rather than accumulate: a
     * payslip whose reimbursement is edited down releases the claims it can no longer
     * cover, and one edited to nothing releases all of them.
     *
     * Takes a `PayslipSettlement` rather than a `Payslip` because the contract Payroll asks through cannot
     * name a Payroll model — see App\Support\PayslipSettlement.
     */
    public function settleForPayslip(PayslipSettlement $settlement): void
    {
        if (! modules()->enabled('expenses')) {
            return;
        }

        $this->settle($settlement);
    }

    /**
     * The settlement itself, returning what it wrote.
     *
     * Separate from the contract method so the return value survives: the interface is `void` because no
     * caller across the boundary uses it, but this module's own tests assert on the settled claims.
     *
     * @return Collection<int, ExpenseClaim> the claims now settled by this payslip
     */
    public function settle(PayslipSettlement $settlement): Collection
    {
        $budget = round($settlement->amount, 2);
        $payslipId = $settlement->payslipId;

        // Its own claims first: they are already counted in the figure above, and
        // dropping them to make room for a newer claim would be churn for nothing.
        $candidates = ExpenseClaim::query()
            ->where('employee_id', $settlement->employeeId)
            ->where(fn ($query) => $query
                ->where('payslip_id', $payslipId)
                ->orWhere('status', ExpenseClaim::STATUS_APPROVED))
            ->orderByRaw('CASE WHEN payslip_id = ? THEN 0 ELSE 1 END', [$payslipId])
            ->orderBy('claimed_on')
            ->get();

        $settled = collect();

        foreach ($candidates as $claim) {
            $amount = round((float) $claim->amount, 2);

            if ($amount <= $budget) {
                $budget = round($budget - $amount, 2);

                if ($claim->payslip_id !== $payslipId || ! $claim->isSettled()) {
                    $claim->update([
                        'status' => ExpenseClaim::STATUS_SETTLED,
                        'payslip_id' => $payslipId,
                    ]);
                }

                $settled->push($claim);

                continue;
            }

            // Doesn't fit. If this payslip was carrying it, it no longer is —
            // partial reimbursement of one claim is not a thing this models.
            if ($claim->payslip_id === $payslipId) {
                $this->release($claim);
            }
        }

        return $settled;
    }

    /**
     * The claims a payslip is carrying, asked for **before** it is deleted.
     *
     * `expense_claims.payslip_id` is `nullOnDelete`, so once the payslip is gone the link is gone with it and
     * there is nothing left to find. Payroll notes these ids in its `deleting` hook and hands them back to
     * `releaseAll()` in `deleted`.
     *
     * @return array<int, int|string>
     */
    public function pendingReleaseFor(int|string $payslipId): array
    {
        if (! modules()->enabled('expenses')) {
            return [];
        }

        return ExpenseClaim::where('payslip_id', $payslipId)->pluck('id')->all();
    }

    /**
     * Return the noted claims to approved and unpaid — a claim whose payslip is gone is owed again.
     *
     * @param  array<int, int|string>  $claimIds
     */
    public function releaseAll(array $claimIds): void
    {
        if (! modules()->enabled('expenses') || $claimIds === []) {
            return;
        }

        foreach (ExpenseClaim::whereIn('id', $claimIds)->get() as $claim) {
            $this->release($claim);
        }
    }

    /**
     * Put a claim back to approved and unpaid — when the payslip that reimbursed it
     * is deleted, or no longer covers it.
     */
    public function release(ExpenseClaim $claim): ExpenseClaim
    {
        $claim->update([
            'status' => ExpenseClaim::STATUS_APPROVED,
            'payslip_id' => null,
        ]);

        return $claim;
    }

    /**
     * Every claim a payslip is reimbursing, released.
     *
     * Only usable while the payslip still exists: expense_claims.payslip_id is
     * nullOnDelete, so once it is gone the link is gone with it and there is nothing
     * left to find. Payslip::booted() notes the ids before deleting for that reason.
     */
    public function releaseAllFor(Payslip $payslip): int
    {
        $claims = ExpenseClaim::where('payslip_id', $payslip->getKey())->get();

        foreach ($claims as $claim) {
            $this->release($claim);
        }

        return $claims->count();
    }
}
