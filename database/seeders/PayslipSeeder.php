<?php

namespace Database\Seeders;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\Payslip;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class PayslipSeeder extends Seeder
{
    /**
     * Generate payslips for every employee for each month of the active
     * fiscal year up to and including the current month. Only input fields
     * are set here — the Payslip::booted() hook runs PayslipService, the
     * annual tax sync, and the payroll journal posting, so all amounts are
     * computed exactly as in production.
     */
    public function run()
    {
        $fiscalYear = FiscalYear::where('is_active', true)->first()
            ?? FiscalYear::where('name', '2026-2027')->first();

        if (! $fiscalYear) {
            $this->command?->warn('No fiscal year found; run FiscalYearSeeder first.');

            return;
        }

        $employees = Employee::where('is_active', 1)->orderBy('id')->get();

        if ($employees->isEmpty()) {
            $this->command?->warn('No employees found; run EmployeeSeeder first.');

            return;
        }

        $start = Carbon::parse($fiscalYear->start_date)->startOfMonth();
        $end = Carbon::now()->startOfMonth();
        $fiscalEnd = Carbon::parse($fiscalYear->end_date)->startOfMonth();

        if ($end->greaterThan($fiscalEnd)) {
            $end = $fiscalEnd;
        }

        if ($start->greaterThan($end)) {
            $this->command?->warn('Active fiscal year has no elapsed months to seed.');

            return;
        }

        $created = 0;
        $skipped = 0;

        for ($date = $start->copy(), $index = 0; $date->lessThanOrEqualTo($end); $date->addMonth(), $index++) {
            $workingDays = $this->weekdaysInMonth($date);

            foreach ($employees as $employee) {
                // Deterministic variety: an occasional bonus, some extra
                // hours, or a day of unpaid leave, varying by employee/month.
                $seed = $employee->id + $index;
                $bonus = ($seed % 5 === 0) ? 15000 : 0;
                $extraHours = ($seed % 4 === 0) ? 2500 : 0;
                $lopDays = ($seed % 7 === 0) ? 1 : 0;

                $payslip = Payslip::firstOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'month' => $date->format('F'),
                        'fiscal_year_id' => $fiscalYear->id,
                    ],
                    [
                        'total_working_days' => $workingDays,
                        'paid_days' => $workingDays - $lopDays,
                        'lop_days' => $lopDays,
                        'leaves_taken' => $lopDays,
                        'bonus' => $bonus,
                        'extra_work_hours' => $extraHours,
                    ]
                );

                $payslip->wasRecentlyCreated ? $created++ : $skipped++;
            }
        }

        $this->command?->info("Seeded {$created} payslips ({$skipped} already existed) for fiscal year {$fiscalYear->name}.");
    }

    private function weekdaysInMonth(Carbon $month): int
    {
        $days = 0;
        $cursor = $month->copy()->startOfMonth();
        $last = $month->copy()->endOfMonth();

        while ($cursor->lessThanOrEqualTo($last)) {
            if ($cursor->isWeekday()) {
                $days++;
            }
            $cursor->addDay();
        }

        return $days;
    }
}
