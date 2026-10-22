<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Pages\Reports;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Filament\Pages\EobiReturnFile;
use App\Modules\Payroll\Models\EmployeeSettingComponent;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\EobiReturn;
use App\Modules\Payroll\Services\StatutoryContributions;
use App\Modules\Payroll\Support\PayrollReports;
use Database\Seeders\PayComponentSeeder;
use Database\Seeders\StatutoryComponentSeeder;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The EOBI return, reachable — `docs/bexio-gap-plan.md` Phase 5, item 17.
 *
 * `EobiReturn` was written and nothing referenced it. The service's own behaviour is asserted lightly;
 * what this file pins is that the page exists in the hub, draws in the pane, and releases the file.
 */
class EobiReturnPageTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'eobi@test.local'));
        $this->setCurrentTenant();

        $this->seed(PayComponentSeeder::class);
        $this->seed(StatutoryComponentSeeder::class);

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'worker@test.local')->id,
            'employee_id' => 'EMP-EOBI',
            'gender' => 'Male',
            'nic' => '35201-1234567-1',
        ]);

        $setting = EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 40000,
        ]);

        foreach ([StatutoryContributions::COMPONENT_EOBI_EMPLOYEE => 370, StatutoryContributions::COMPONENT_EOBI_EMPLOYER => 1850] as $code => $amount) {
            EmployeeSettingComponent::create([
                'employee_setting_id' => $setting->id,
                'pay_component_id' => PayComponent::where('code', $code)->firstOrFail()->id,
                'amount' => $amount,
            ]);
        }

        Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);
    }

    public function test_the_return_reads_what_the_payslips_carry(): void
    {
        $rows = app(EobiReturn::class)->rows('July', $this->fiscalYear);

        $this->assertCount(1, $rows);
        $this->assertSame('EMP-EOBI', $rows[0]['employee_code']);
        $this->assertSame(370.0, $rows[0]['employee_contribution']);
        $this->assertSame(1850.0, $rows[0]['employer_contribution']);
        $this->assertNull(app(EobiReturn::class)->readiness('July', $this->fiscalYear));
    }

    public function test_the_return_is_in_the_hub_and_draws_in_the_pane(): void
    {
        $this->assertArrayHasKey('EobiReturnFile', Reports::catalogue());

        $pane = app(PayrollReports::class)->eobiReturn('2026-07-15');

        $this->assertSame('file', $pane['kind']);
        $this->assertSame(1, $pane['rows_count']);
        $this->assertSame(2220.0, $pane['tiles'][1]['value'], 'employee plus employer, what the payment will be');
    }

    public function test_the_page_renders_and_releases_the_file(): void
    {
        Livewire::test(EobiReturnFile::class)
            ->fillForm(['fiscal_year_id' => $this->fiscalYear->id, 'month' => 'July'])
            ->assertSee('EMP-EOBI')
            ->assertActionVisible('downloadEobiReturn');

        $csv = app(EobiReturn::class)->csv('July', $this->fiscalYear);

        $this->assertStringStartsWith('employee_code,name,cnic,employee_contribution,employer_contribution', $csv);
        $this->assertStringContainsString('EMP-EOBI,', $csv);
        $this->assertStringContainsString(',370.00,1850.00', $csv);
    }

    public function test_a_month_with_nothing_says_why(): void
    {
        Livewire::test(EobiReturnFile::class)
            ->fillForm(['fiscal_year_id' => $this->fiscalYear->id, 'month' => 'August'])
            ->assertSee('carries an EOBI amount')
            ->assertActionHidden('downloadEobiReturn');
    }
}
