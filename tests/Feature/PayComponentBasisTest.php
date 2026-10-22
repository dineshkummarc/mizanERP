<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\EmployeeSettingComponent;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\ComponentAmount;
use Database\Seeders\PayComponentSeeder;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Hourly and annual pay components — `docs/bexio-gap-plan.md` Phase 5, item 20.
 *
 * No new column on payslips: an hourly wage and a 13th salary land in `payslip_components` the way a
 * monthly allowance does. What is asserted is the arithmetic — hours times the rate, the whole amount
 * in the named month and nothing in the others — and that the calculation and the recorder agree.
 */
class PayComponentBasisTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    private EmployeeSetting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'basis@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'accounting', 'attendance'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->seed(PayComponentSeeder::class);

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'hourly@test.local')->id,
            'employee_id' => 'EMP-H',
            'gender' => 'Male',
        ]);

        $this->setting = EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 0,
        ]);
    }

    private function payComponent(string $code, string $basis, array $extra = []): PayComponent
    {
        return PayComponent::create(array_merge([
            'code' => $code,
            'label' => ucfirst(str_replace('_', ' ', $code)),
            'kind' => PayComponent::KIND_EARNING,
            'basis' => $basis,
            'account_id' => Account::where('code', '5100')->firstOrFail()->id,
            'is_taxable' => true,
        ], $extra));
    }

    private function onPackage(PayComponent $component, float $amount): void
    {
        EmployeeSettingComponent::create([
            'employee_setting_id' => $this->setting->id,
            'pay_component_id' => $component->id,
            'amount' => $amount,
        ]);
    }

    private function payslip(string $month): Payslip
    {
        return Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => $month,
            'total_working_days' => 22,
            'paid_days' => 22,
        ])->refresh();
    }

    public function test_an_hourly_component_pays_the_hours_attendance_recorded(): void
    {
        $hourly = $this->payComponent('hourly_wage', PayComponent::BASIS_PER_HOUR);
        $this->onPackage($hourly, 500);

        foreach (['2026-07-01', '2026-07-02'] as $date) {
            AttendanceDay::create([
                'employee_id' => $this->employee->id,
                'date' => $date,
                'status' => AttendanceDay::STATUS_PRESENT,
                'worked_minutes' => 480,
                'source' => 'manual',
            ]);
        }

        $this->assertSame(16.0, app(ComponentAmount::class)->hoursWorked($this->employee->id, 'July', $this->fiscalYear));

        $payslip = $this->payslip('July');

        $this->assertSame(8000.0, (float) $payslip->total_earnings, '16 hours at 500');
        $this->assertSame(8000.0, (float) $payslip->components()->where('pay_component_id', $hourly->id)->value('amount'));

        // A month with no recorded hours pays nothing, rather than a guess.
        $august = $this->payslip('August');
        $this->assertSame(0.0, (float) $august->total_earnings);
        $this->assertNull($august->components()->where('pay_component_id', $hourly->id)->value('amount'));
    }

    public function test_an_annual_component_pays_whole_in_its_month_and_nothing_in_the_others(): void
    {
        $thirteenth = $this->payComponent('thirteenth_salary', PayComponent::BASIS_ANNUAL, ['pays_in_month' => 'December']);
        $this->onPackage($thirteenth, 400_000);

        $july = $this->payslip('July');
        $this->assertSame(0.0, (float) $july->total_earnings);
        $this->assertNull($july->components()->where('pay_component_id', $thirteenth->id)->value('amount'));

        $december = $this->payslip('December');
        $this->assertSame(400_000.0, (float) $december->total_earnings);
        $this->assertSame(400_000.0, (float) $december->components()->where('pay_component_id', $thirteenth->id)->value('amount'));
    }

    public function test_a_monthly_component_is_unchanged(): void
    {
        $allowance = $this->payComponent('internet_allowance', PayComponent::BASIS_MONTHLY);
        $this->onPackage($allowance, 3000);

        $this->assertSame(3000.0, (float) $this->payslip('July')->total_earnings);
        $this->assertSame(PayComponent::BASIS_MONTHLY, PayComponent::where('code', 'basic_wage')->value('basis'), 'the shipped components are monthly by default');
    }
}
