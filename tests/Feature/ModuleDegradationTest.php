<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\Payslip;
use App\Support\Modules;
use Tests\AccountingTestCase;

/**
 * Cross-module writes degrade; they do not throw.
 *
 * A licence can be revoked out from under live data, so the interesting case is
 * not "can Payroll be used without Accounting" but "does creating a payslip still
 * work the moment Accounting disappears".
 */
class ModuleDegradationTest extends AccountingTestCase
{
    private Company $company;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        // Single-database suite: drop the DB-switch task, as StatusPageTest does.
        config(['multitenancy.switch_tenant_tasks' => [
            \App\Multitenancy\Tasks\SetPermissionsTeamIdTask::class,
            \App\Multitenancy\Tasks\SwitchTenantFilesystemTask::class,
        ]]);

        $this->company = Company::factory()->create();
        $this->company->makeCurrent();

        $user = User::factory()->create();
        $this->company->users()->attach($user->getKey());
        $this->actingAs($user);

        $this->employee = Employee::create([
            'user_id' => $user->getKey(),
            'employee_id' => 'E-100',
            'gender' => 'Male',
            'phone' => 'ph-100',
        ]);

        EmployeeSetting::create([
            'employee_id' => $this->employee->getKey(),
            'fiscal_year_id' => $this->fiscalYear->getKey(),
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 200000,
        ]);
    }

    protected function tearDown(): void
    {
        Company::forgetCurrent();

        parent::tearDown();
    }

    private function setModule(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->company->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    private function createPayslip(): Payslip
    {
        return Payslip::create([
            'employee_id' => $this->employee->getKey(),
            'fiscal_year_id' => $this->fiscalYear->getKey(),
            'month' => 'July',
            'total_working_days' => 22,
            'paid_days' => 22,
            'basic_wage' => 100000,
            'net_salary' => 90000,
        ]);
    }

    public function test_a_payslip_is_still_created_with_accounting_switched_off(): void
    {
        $this->setModule('accounting', false);

        $payslip = $this->createPayslip();

        $this->assertTrue($payslip->exists, 'Payroll must not fail because the books are elsewhere.');
        $this->assertSame(
            0,
            JournalEntry::forSource(Payslip::class, $payslip->getKey())->count(),
            'and nothing may be posted into a module the company does not have.'
        );
    }

    public function test_the_same_payslip_does_post_when_accounting_is_on(): void
    {
        // Proves the degradation above is a guard, not a broken posting path.
        $this->setModule('accounting', true);

        $payslip = $this->createPayslip();

        $this->assertGreaterThan(
            0,
            JournalEntry::forSource(Payslip::class, $payslip->getKey())->count(),
        );
    }

    public function test_revoking_accounting_takes_invoicing_and_inventory_with_it(): void
    {
        // Invoicing and Inventory declare Accounting as a requirement, so they are
        // unavailable the moment it is — a revoke does not go through the
        // activation form, so the rule has to hold at read time too. This is what
        // stops issue() from posting journal entries into a module the company no
        // longer has, which is why those two need no degradation guard of their own.
        $this->setModule('accounting', true);
        $this->setModule('invoicing', true);
        $this->setModule('inventory', true);

        $id = $this->company->getKey();

        $this->assertTrue(modules()->enabledFor($id, 'invoicing'));

        $this->setModule('accounting', false);

        $this->assertFalse(modules()->enabledFor($id, 'invoicing'));
        $this->assertFalse(modules()->enabledFor($id, 'inventory'));
        $this->assertTrue(
            modules()->licensedFor($id, 'invoicing'),
            'The licence is untouched — only availability changes, so restoring Accounting restores it.'
        );
    }

    public function test_payroll_is_not_taken_down_by_accounting(): void
    {
        // Payroll deliberately does not declare Accounting: it degrades instead,
        // which is the difference between the two kinds of dependency.
        $this->assertNotContains('accounting', Modules::requirements('payroll'));

        $this->setModule('payroll', true);
        $this->setModule('accounting', false);

        $this->assertTrue(modules()->enabledFor($this->company->getKey(), 'payroll'));
    }

    public function test_payroll_still_requires_employees(): void
    {
        $this->setModule('payroll', true);
        $this->setModule('employees', false);

        $this->assertFalse(
            modules()->enabledFor($this->company->getKey(), 'payroll'),
            'Payslips key on employees, so that dependency is hard.'
        );
    }
}
