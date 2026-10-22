<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\EmployeeSettingComponent;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use App\Support\PayrollMonth;

/**
 * Writes what a payslip paid, component by component.
 *
 * The column-backed components are copied from the payslip's own columns — they are
 * the same fact, and reading them from anywhere else would let the two disagree.
 * Anything data-driven comes from the employee's package for that month.
 *
 * Reconciled on every save rather than appended: payroll recalculates a payslip each
 * time it is touched, and a component whose amount drops to nothing has to lose its
 * row rather than keep a stale one.
 */
class PayComponentRecorder
{
    /** component code => the payslip column holding the same figure. */
    private const COLUMN_FOR = [
        'basic_wage' => 'basic_wage',
        'medical_allowance' => 'medical_allowance',
        'petrol_allowance' => 'petrol_allowance',
        'device_allowance' => 'device_allowance',
        'bonus' => 'bonus',
        'extra_work_hours' => 'extra_work_hours',
        'expense_reimbursement' => 'expense_reimbursement',
        'withholding_tax' => 'withholding_tax',
        'advances' => 'advances',
        'meal_deduction' => 'meal_deduction',
        'esi_health_insurance' => 'esi_health_insurance',
    ];

    public function record(Payslip $payslip): void
    {
        $amounts = $this->amountsFor($payslip);

        foreach ($amounts as $componentId => $amount) {
            if (round($amount, 2) === 0.0) {
                $payslip->components()->where('pay_component_id', $componentId)->delete();

                continue;
            }

            $payslip->components()->updateOrCreate(
                ['pay_component_id' => $componentId],
                ['amount' => round($amount, 2)],
            );
        }

        // Anything no longer part of pay at all — a component deactivated, or removed
        // from the package — loses its row too.
        $payslip->components()->whereNotIn('pay_component_id', array_keys($amounts))->delete();
    }

    /**
     * @return array<int, float> component id => amount
     */
    private function amountsFor(Payslip $payslip): array
    {
        $amounts = [];

        foreach (PayComponent::active()->get() as $component) {
            if ($component->is_column_backed) {
                $column = self::COLUMN_FOR[$component->code] ?? null;

                $amounts[$component->getKey()] = $column
                    ? round((float) ($payslip->{$column} ?? 0), 2)
                    : 0.0;

                continue;
            }

            $amounts[$component->getKey()] = $this->packagedAmount($payslip, $component);
        }

        return $amounts;
    }

    /**
     * What the employee's package says this component is worth in this month — through ComponentAmount,
     * so an hourly wage or a 13th salary is recorded as the calculation paid it.
     */
    private function packagedAmount(Payslip $payslip, PayComponent $component): float
    {
        $setting = $this->settingFor($payslip);

        if (! $setting) {
            return 0.0;
        }

        $row = EmployeeSettingComponent::query()
            ->where('employee_setting_id', $setting->getKey())
            ->where('pay_component_id', $component->getKey())
            ->first();

        if (! $row) {
            return 0.0;
        }

        $row->setRelation('component', $component);

        return app(ComponentAmount::class)->monthly($row, $payslip->employee_id, $payslip->month, $payslip->fiscalYear);
    }

    private function settingFor(Payslip $payslip): ?EmployeeSetting
    {
        return EmployeeSetting::getActiveSettingForDate(
            $payslip->employee_id,
            PayrollMonth::firstDay($payslip->month, $payslip->fiscalYear)->toDateString(),
            $payslip->fiscal_year_id,
        );
    }
}
