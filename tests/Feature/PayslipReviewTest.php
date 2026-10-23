<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\Payslip;
use Tests\AccountingTestCase;

class PayslipReviewTest extends AccountingTestCase
{
    private User $employeeUser;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employeeUser = $this->makeUser('Employee', 'review-emp@test.local');

        $this->employee = Employee::create([
            'user_id' => $this->employeeUser->id,
            'employee_id' => 'EMP-RV-1',
            'phone' => '0300-0000000',
            'gender' => 'Male',
            'is_active' => 1,
        ]);

        EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 200000,
            'medical_allowance' => 20000,
            'device_allowance' => 5000,
            'petrol_allowance' => 13500,
            'advances' => 10000,
            'meal_deduction' => 2000,
            'esi_health_insurance' => 1500,
        ]);
    }

    private function makePayslip(): Payslip
    {
        return Payslip::create([
            'employee_id' => $this->employee->id,
            'month' => 'July',
            'fiscal_year_id' => $this->fiscalYear->id,
            'total_working_days' => 22,
            'paid_days' => 22,
            'lop_days' => 0,
            'leaves_taken' => 0,
        ]);
    }

    public function test_payslip_creators_have_permission(): void
    {
        foreach (['Accountant', 'Manager', 'CEO'] as $role) {
            $user = $this->makeUser($role, strtolower($role).'-ps@test.local');
            $this->assertTrue($user->can('create', Payslip::class), "{$role} should create payslips");
        }

        $this->assertFalse($this->employeeUser->can('create', Payslip::class));
    }

    public function test_new_payslip_is_pending_review(): void
    {
        $this->assertTrue($this->makePayslip()->isPendingReview());
    }

    public function test_accept_records_review_without_touching_figures(): void
    {
        $payslip = $this->makePayslip();
        $netBefore = $payslip->net_salary;
        $entryCount = $payslip->journalEntries()->count();

        $payslip->recordEmployeeReview(Payslip::REVIEW_ACCEPTED);

        $payslip->refresh();
        $this->assertSame(Payslip::REVIEW_ACCEPTED, $payslip->employee_review);
        $this->assertNotNull($payslip->employee_reviewed_at);
        $this->assertEquals($netBefore, $payslip->net_salary);
        $this->assertSame($entryCount, $payslip->journalEntries()->count());
    }

    public function test_reject_records_reason_and_blocks_nothing(): void
    {
        $payslip = $this->makePayslip();

        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime hours missing');

        $payslip->refresh();
        $this->assertSame(Payslip::REVIEW_REJECTED, $payslip->employee_review);
        $this->assertSame('Overtime hours missing', $payslip->employee_rejection_reason);

        // Accounts team can still edit the payslip afterwards (rejection is advisory).
        $payslip->update(['bonus' => 5000]);
        $this->assertEquals(5000, $payslip->refresh()->bonus);
    }

    public function test_review_cannot_happen_twice(): void
    {
        $payslip = $this->makePayslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_ACCEPTED);

        $this->expectException(\InvalidArgumentException::class);
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'changed my mind');
    }

    public function test_rejection_notifies_payslip_staff_not_the_employee(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $accountant = $this->makeUser('Accountant', 'acct-notify@test.local');
        $manager = $this->makeUser('Manager', 'mgr-ps-notify@test.local');

        $payslip = $this->makePayslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Wrong deductions');

        \Illuminate\Support\Facades\Notification::assertSentTo(
            [$accountant, $manager],
            \App\Notifications\PayslipRejected::class
        );
        \Illuminate\Support\Facades\Notification::assertNotSentTo(
            $this->employeeUser,
            \App\Notifications\PayslipRejected::class
        );
    }

    public function test_acceptance_sends_no_notification(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $this->makeUser('Accountant', 'acct-quiet@test.local');
        $this->makePayslip()->recordEmployeeReview(Payslip::REVIEW_ACCEPTED);

        \Illuminate\Support\Facades\Notification::assertNothingSent();
    }

    public function test_owner_can_run_review_actions_others_cannot(): void
    {
        $payslip = $this->makePayslip();
        $otherEmployee = $this->makeUser('Employee', 'other-emp-rv@test.local');

        $this->assertTrue(\Gate::forUser($this->employeeUser)->check('runAction', [$payslip, null]));
        $this->assertFalse(\Gate::forUser($otherEmployee)->check('runAction', [$payslip, null]));
    }
}
