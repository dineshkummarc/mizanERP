<?php

namespace Tests\Feature;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Resources\EmployeeSettings\Pages\ListEmployeeSettings;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Filament\Resources\AnnualTaxes\Pages\ListAnnualTaxes;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\ListPayslips;
use App\Modules\Payroll\Models\AnnualTax;
use App\Modules\Payroll\Models\Payslip;
use App\Support\LandlordUserColumn;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Searching a tenant table by the owning user's name.
 *
 * `users` lives in the landlord database; payslips, employee settings and annual
 * taxes live in a per-company one. A `whereHas('employee.user', …)` compiles to
 * one statement naming `users` while running on the tenant connection, which in
 * production fails with:
 *
 *   Base table or view not found: 1146 Table 'tenant_….users' doesn't exist
 *
 * It surfaces on the paginator's `select count(*)`, so the page dies rather than
 * merely returning nothing.
 *
 * This suite runs landlord and tenant in ONE sqlite database, so the broken
 * pattern passes here — that is precisely why the structural assertion below
 * exists alongside the behavioural ones.
 */
class CrossDatabaseSearchTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->actingAs($this->makeUser('Administrator', 'xdb@test.local'));
        $this->setCurrentTenant();
    }

    /**
     * The guard the single-database harness cannot provide: no Filament table
     * may reach the landlord `users` table through a relationship subquery.
     */
    public function test_no_filament_table_searches_across_the_database_boundary(): void
    {
        $offenders = [];

        // Case-insensitive on purpose: the pattern that actually reached
        // production was `orWhereHas('user', …)`, whose capital W a lowercase
        // substring search misses entirely.
        $pattern = '/(?:or)?where(?:Doesnt)?Has\(\s*[\'"](?:user|employee\.user)[\'"]/i';

        foreach (glob(app_path('Filament/Resources/*/Tables/*.php')) as $file) {
            if (preg_match_all($pattern, file_get_contents($file), $matches)) {
                foreach ($matches[0] as $match) {
                    $offenders[] = basename($file).' → '.$match;
                }
            }
        }

        $this->assertSame([], $offenders,
            "These build a cross-database subquery against the landlord `users` table.\n"
            .'Resolve ids first with App\Support\LandlordUserColumn instead.');
    }

    private function employee(string $name, string $employeeId): Employee
    {
        $user = User::factory()->create(['name' => $name]);

        return Employee::create([
            'user_id' => $user->id,
            'employee_id' => $employeeId,
            'gender' => 'Male',
            'is_active' => true,
        ]);
    }

    public function test_employee_ids_matching_finds_by_user_name_and_by_code(): void
    {
        $ali = $this->employee('Ali Raza', 'EMP-101');
        $sara = $this->employee('Sara Khan', 'EMP-202');

        $this->assertSame([$ali->id], LandlordUserColumn::employeeIdsMatching('Ali Raza'));
        $this->assertSame([$sara->id], LandlordUserColumn::employeeIdsMatching('EMP-202'));
        $this->assertSame([], LandlordUserColumn::employeeIdsMatching('nobody at all'));
        $this->assertSame([], LandlordUserColumn::employeeIdsMatching('   '));
    }

    public function test_payslip_search_matches_the_employees_user_name(): void
    {
        $year = FiscalYear::where('is_active', true)->firstOrFail();
        $ali = $this->employee('Ali Raza', 'EMP-101');
        $sara = $this->employee('Sara Khan', 'EMP-202');

        $mine = Payslip::create(['employee_id' => $ali->id, 'fiscal_year_id' => $year->id, 'month' => 'July', 'basic_wage' => 1]);
        $other = Payslip::create(['employee_id' => $sara->id, 'fiscal_year_id' => $year->id, 'month' => 'July', 'basic_wage' => 1]);

        Livewire::test(ListPayslips::class)
            ->searchTable('Ali Raza')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_employee_settings_search_matches_the_users_name(): void
    {
        $year = FiscalYear::where('is_active', true)->firstOrFail();
        $ali = $this->employee('Ali Raza', 'EMP-101');
        $sara = $this->employee('Sara Khan', 'EMP-202');

        // start_date is required: the model's creating hook compares against it.
        $mine = EmployeeSetting::create(['employee_id' => $ali->id, 'fiscal_year_id' => $year->id, 'basic_wage' => 1, 'start_date' => '2026-07-01']);
        $other = EmployeeSetting::create(['employee_id' => $sara->id, 'fiscal_year_id' => $year->id, 'basic_wage' => 1, 'start_date' => '2026-07-01']);

        Livewire::test(ListEmployeeSettings::class)
            ->searchTable('Ali Raza')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_annual_tax_search_matches_the_users_name(): void
    {
        $year = FiscalYear::where('is_active', true)->firstOrFail();
        $ali = $this->employee('Ali Raza', 'EMP-101');
        $sara = $this->employee('Sara Khan', 'EMP-202');

        $mine = AnnualTax::create(['employee_id' => $ali->id, 'fiscal_year_id' => $year->id]);
        $other = AnnualTax::create(['employee_id' => $sara->id, 'fiscal_year_id' => $year->id]);

        Livewire::test(ListAnnualTaxes::class)
            ->searchTable('Ali Raza')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_a_search_that_matches_nothing_returns_no_rows_rather_than_everything(): void
    {
        $year = FiscalYear::where('is_active', true)->firstOrFail();
        $ali = $this->employee('Ali Raza', 'EMP-101');
        $payslip = Payslip::create(['employee_id' => $ali->id, 'fiscal_year_id' => $year->id, 'month' => 'July', 'basic_wage' => 1]);

        Livewire::test(ListPayslips::class)
            ->searchTable('zzz-no-such-person')
            ->assertCanNotSeeTableRecords([$payslip]);
    }
}
