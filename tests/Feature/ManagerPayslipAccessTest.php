<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\Payslip;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ManagerPayslipAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_access_reports_payslip_and_employee(): void
    {
        $this->seed(PermissionSeeder::class);
        $company = Company::factory()->create();
        app()->instance('currentTenant', $company);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        (new RoleSeeder)->run();

        $mgrUser = User::factory()->create();
        $mgrUser->assignRole('Employee');
        $manager = Employee::create(['user_id' => $mgrUser->id, 'employee_id' => 'EMP-M', 'phone' => '1', 'gender' => 'Male', 'is_active' => 1]);

        $repUser = User::factory()->create();
        $report = Employee::create(['user_id' => $repUser->id, 'employee_id' => 'EMP-R', 'phone' => '2', 'gender' => 'Male', 'is_active' => 1, 'manager_id' => $manager->id]);
        $reportPayslip = Payslip::withoutEvents(fn () => Payslip::create(['employee_id' => $report->id, 'month' => 'January', 'net_salary' => 100, 'employee_review' => 'pending']));

        $stranger = Employee::create(['user_id' => User::factory()->create()->id, 'employee_id' => 'EMP-S', 'phone' => '3', 'gender' => 'Male', 'is_active' => 1]);
        $strangerPayslip = Payslip::withoutEvents(fn () => Payslip::create(['employee_id' => $stranger->id, 'month' => 'January', 'net_salary' => 100]));

        // Manager over a report:
        $this->assertTrue($mgrUser->can('view', $reportPayslip));
        $this->assertTrue($mgrUser->can('runAction', $reportPayslip));   // Download
        $this->assertTrue($mgrUser->can('view', $report));
        $this->assertTrue($mgrUser->can('update', $report));             // edit employee data

        // Not over unrelated employees:
        $this->assertFalse($mgrUser->can('view', $strangerPayslip));
        $this->assertFalse($mgrUser->can('update', $stranger));
    }
}
