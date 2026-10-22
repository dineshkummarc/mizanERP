<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Payroll\Models\EmployeeSettingComponent;
use App\Modules\Payroll\Models\PayComponent;
use App\Support\PayrollMonth;

/**
 * What a data-driven component pays in a given month — `docs/bexio-gap-plan.md` Phase 5, item 20.
 *
 * One place, read by both the payslip calculation (which sums the month) and the recorder (which writes
 * what was paid), so the two cannot disagree about an hourly wage or a 13th salary:
 *
 *  - **monthly** — the amount on the package, as it always was.
 *  - **per_hour** — the amount times the hours attendance recorded in the month. Without the Attendance
 *    module there are no hours, and an hourly component pays nothing rather than guessing a month.
 *  - **annual** — the whole amount in the month it is paid in, nothing in the others.
 */
class ComponentAmount
{
    public function monthly(EmployeeSettingComponent $row, int|string $employeeId, string $month, ?FiscalYear $fiscalYear): float
    {
        $component = $row->component;
        $amount = round((float) $row->amount, 2);

        if (! $component instanceof PayComponent) {
            return $amount;
        }

        return match ($component->basis) {
            PayComponent::BASIS_ANNUAL => $component->pays_in_month === $month ? $amount : 0.0,
            PayComponent::BASIS_PER_HOUR => round($amount * $this->hoursWorked($employeeId, $month, $fiscalYear), 2),
            default => $amount,
        };
    }

    /**
     * A month's worth, for projecting the year's tax: an annual amount spread over twelve rather than
     * paid whole or not at all, which is what it is to the year's taxable income. Everything else is
     * what the month pays.
     */
    public function projectedMonthly(EmployeeSettingComponent $row, int|string $employeeId, string $month, ?FiscalYear $fiscalYear): float
    {
        $component = $row->component;

        if ($component instanceof PayComponent && $component->basis === PayComponent::BASIS_ANNUAL) {
            return round((float) $row->amount / 12, 2);
        }

        return $this->monthly($row, $employeeId, $month, $fiscalYear);
    }

    /** Hours attendance recorded for the employee in the month, from the days' worked minutes. */
    public function hoursWorked(int|string $employeeId, string $month, ?FiscalYear $fiscalYear): float
    {
        if ($fiscalYear === null || ! modules()->enabled('attendance')) {
            return 0.0;
        }

        $first = PayrollMonth::firstDay($month, $fiscalYear);
        $last = $first->copy()->endOfMonth();

        $minutes = (int) AttendanceDay::query()
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$first->toDateString(), $last->toDateString()])
            ->sum('worked_minutes');

        return round($minutes / 60, 2);
    }
}
