<?php

namespace Tests\Feature;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Resources\Employees\Pages\ListEmployees;
use App\Modules\Employees\Filament\Resources\EmployeeSettings\Pages\ListEmployeeSettings;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Filament\Resources\AnnualTaxes\Pages\ListAnnualTaxes;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\ListPayslips;
use App\Modules\Payroll\Models\AnnualTax;
use App\Modules\Payroll\Models\Payslip;
use Database\Seeders\FiscalYearSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\UsesRealTenantDatabase;
use Tests\TestCase;

/**
 * The cross-database bugs, caught for real.
 *
 * Everything here runs with the landlord on the default connection and the
 * tenant on its own SQLite file, so a query that spans the two actually fails —
 * the way it does in production and the way the single-database suite cannot
 * reproduce.
 */
class RealTenantDatabaseSearchTest extends TestCase
{
    use RefreshDatabase;
    use UsesRealTenantDatabase;

    private FiscalYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);

        $this->bootRealTenant('acme');
        $this->seedTenant([FiscalYearSeeder::class]);

        $this->year = FiscalYear::orderByDesc('start_date')->firstOrFail();

        $this->actingAsTenantUser(User::factory()->create(['name' => 'Panel Admin']));
    }

    private function employee(string $name, string $employeeId): Employee
    {
        return Employee::create([
            'user_id' => User::factory()->create(['name' => $name])->id,
            'employee_id' => $employeeId,
            'gender' => 'Male',
            'is_active' => true,
        ]);
    }

    /**
     * The harness has teeth: this is the exact shape that took production down,
     * and here it throws instead of quietly working.
     */
    public function test_a_relationship_subquery_into_the_landlord_users_table_throws(): void
    {
        $this->assertTenantDatabaseIsSeparate();

        $this->employee('Ali Raza', 'EMP-101');

        try {
            Payslip::whereHas('employee', fn ($q) => $q
                ->whereHas('user', fn ($u) => $u->where('name', 'like', '%Ali%')))
                ->count();

            $this->fail('a cross-database subquery should not succeed');
        } catch (QueryException $e) {
            $this->assertStringContainsString('users', $e->getMessage());
        }
    }

    public function test_the_landlord_and_tenant_models_each_reach_their_own_database(): void
    {
        $employee = $this->employee('Sara Khan', 'EMP-202');

        // Employee is a tenant model; its user is a landlord one.
        $this->assertSame('tenant', $employee->getConnectionName());
        $this->assertSame(config('database.default'), $employee->user->getConnectionName());
        $this->assertSame('Sara Khan', $employee->user->name);
    }

    public function test_payslip_search_by_user_name_works_across_the_boundary(): void
    {
        $ali = $this->employee('Ali Raza', 'EMP-101');
        $sara = $this->employee('Sara Khan', 'EMP-202');

        $mine = Payslip::create(['employee_id' => $ali->id, 'fiscal_year_id' => $this->year->id, 'month' => 'July', 'basic_wage' => 1]);
        $other = Payslip::create(['employee_id' => $sara->id, 'fiscal_year_id' => $this->year->id, 'month' => 'July', 'basic_wage' => 1]);

        Livewire::test(ListPayslips::class)
            ->searchTable('Ali Raza')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_employee_settings_search_by_user_name_works_across_the_boundary(): void
    {
        $ali = $this->employee('Ali Raza', 'EMP-101');
        $sara = $this->employee('Sara Khan', 'EMP-202');

        $mine = EmployeeSetting::create(['employee_id' => $ali->id, 'fiscal_year_id' => $this->year->id, 'basic_wage' => 1, 'start_date' => '2026-07-01']);
        $other = EmployeeSetting::create(['employee_id' => $sara->id, 'fiscal_year_id' => $this->year->id, 'basic_wage' => 1, 'start_date' => '2026-07-01']);

        Livewire::test(ListEmployeeSettings::class)
            ->searchTable('Ali Raza')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_annual_tax_search_by_user_name_works_across_the_boundary(): void
    {
        $ali = $this->employee('Ali Raza', 'EMP-101');
        $sara = $this->employee('Sara Khan', 'EMP-202');

        $mine = AnnualTax::create(['employee_id' => $ali->id, 'fiscal_year_id' => $this->year->id]);
        $other = AnnualTax::create(['employee_id' => $sara->id, 'fiscal_year_id' => $this->year->id]);

        Livewire::test(ListAnnualTaxes::class)
            ->searchTable('Ali Raza')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other]);
    }

    /**
     * The employees listing searches and sorts by the landlord user's name
     * through LandlordUserColumn — the original reason that helper exists.
     */
    public function test_the_employees_listing_searches_and_sorts_by_user_name(): void
    {
        $ali = $this->employee('Ali Raza', 'EMP-101');
        $sara = $this->employee('Sara Khan', 'EMP-202');

        Livewire::test(ListEmployees::class)
            ->loadTable()
            ->searchTable('Sara')
            ->assertCanSeeTableRecords([$sara])
            ->assertCanNotSeeTableRecords([$ali]);

        Livewire::test(ListEmployees::class)
            ->loadTable()
            ->sortTable('user.name')
            ->assertCanSeeTableRecords([$ali, $sara], inOrder: true)
            ->sortTable('user.name', 'desc')
            ->assertCanSeeTableRecords([$sara, $ali], inOrder: true);
    }

    /** Listing pages must paginate — the count(*) is where this used to die. */
    public function test_the_listings_render_and_paginate_over_the_boundary(): void
    {
        $employee = $this->employee('Ali Raza', 'EMP-101');
        Payslip::create(['employee_id' => $employee->id, 'fiscal_year_id' => $this->year->id, 'month' => 'July', 'basic_wage' => 1]);

        foreach ([ListPayslips::class, ListEmployees::class, ListEmployeeSettings::class, ListAnnualTaxes::class] as $page) {
            Livewire::test($page)->assertSuccessful();
        }
    }
}
