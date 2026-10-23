<?php

namespace Database\Seeders;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use Illuminate\Database\Seeder;

class EmployeeSettingSeeder extends Seeder
{
    /**
     * Give every employee a salary setting for the active fiscal year,
     * with basic wages spread evenly across 200,000 – 450,000.
     */
    public function run()
    {
        $fiscalYear = FiscalYear::where('is_active', true)->first()
            ?? FiscalYear::where('name', '2026-2027')->first();

        if (! $fiscalYear) {
            $this->command?->warn('No fiscal year found; run FiscalYearSeeder first.');

            return;
        }

        $employees = Employee::orderBy('id')->get();

        if ($employees->isEmpty()) {
            $this->command?->warn('No employees found; run EmployeeSeeder first.');

            return;
        }

        $minWage = 200000;
        $maxWage = 450000;
        $count = $employees->count();
        // Even spread across the range, rounded to the nearest 1,000.
        $step = $count > 1 ? ($maxWage - $minWage) / ($count - 1) : 0;

        foreach ($employees->values() as $index => $employee) {
            $basicWage = round(($minWage + $step * $index) / 1000) * 1000;

            EmployeeSetting::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'fiscal_year_id' => $fiscalYear->id,
                ],
                [
                    'start_date' => $fiscalYear->start_date?->toDateString() ?? '2026-07-01',
                    'end_date' => $fiscalYear->end_date?->toDateString() ?? '2027-06-30',
                    'basic_wage' => $basicWage,
                    'medical_allowance' => round($basicWage * 0.10),
                    'petrol_allowance' => 13500,
                    'device_allowance' => 5000,
                    'advances' => 0,
                    'meal_deduction' => 6500,
                    'esi_health_insurance' => 0,
                ]
            );
        }

        $this->command?->info("Seeded salary settings for {$count} employees (basic wage {$minWage}–{$maxWage}).");
    }
}
