<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeChangeRequest;
use Tests\AccountingTestCase;

class EmployeeSelfServiceTest extends AccountingTestCase
{
    private User $employeeUser;

    private Employee $employee;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employeeUser = $this->makeUser('Employee', 'self-service@test.local');
        $this->manager = $this->makeUser('Manager', 'mgr-approver@test.local');

        $this->employee = Employee::create([
            'user_id' => $this->employeeUser->id,
            'employee_id' => 'EMP-SS-01',
            'designation' => 'Backend Developer',
            'department' => 'IT',
            'nic' => '12345-1234567-1',
            'date_of_joining' => '2025-01-01',
            'gender' => 'Male',
            'phone' => '0300-0000000',
        ]);
    }

    public function test_employee_edit_creates_pending_request_without_changing_record(): void
    {
        $this->actingAs($this->employeeUser);

        $this->employee->update([
            'nic' => '99999-9999999-9',
            'date_of_joining' => '2025-02-01',
        ]);

        $this->employee->refresh();
        $this->assertSame('12345-1234567-1', $this->employee->nic);
        $this->assertSame('2025-01-01', $this->employee->date_of_joining->toDateString());

        $request = EmployeeChangeRequest::firstOrFail();
        $this->assertSame(EmployeeChangeRequest::STATUS_PENDING, $request->status);
        $this->assertSame('99999-9999999-9', $request->requested_changes['nic']);
        $this->assertSame('12345-1234567-1', $request->original_values['nic']);
        $this->assertSame($this->employeeUser->id, $request->requested_by);
    }

    public function test_transient_user_fields_route_through_request_for_employee(): void
    {
        $this->actingAs($this->employeeUser);

        $this->employee->user_name = 'New Name';
        $this->employee->user_email = 'new-email@test.local';
        $this->employee->save();

        // User untouched until approval.
        $this->assertSame('self-service@test.local', $this->employeeUser->refresh()->email);
        $this->assertSame('Employee', $this->employeeUser->name);

        $request = EmployeeChangeRequest::firstOrFail();
        $this->assertSame('New Name', $request->requested_changes['user_name']);
        $this->assertSame('new-email@test.local', $request->requested_changes['user_email']);
    }

    public function test_approval_applies_changes_to_employee_and_user(): void
    {
        $this->actingAs($this->employeeUser);
        $this->employee->user_name = 'Approved Name';
        $this->employee->nic = '11111-1111111-1';
        $this->employee->save();

        $request = EmployeeChangeRequest::firstOrFail();

        $this->actingAs($this->manager);
        $request->approve($this->manager);

        $this->assertSame('11111-1111111-1', $this->employee->refresh()->nic);
        $this->assertSame('Approved Name', $this->employeeUser->refresh()->name);
        $this->assertSame(EmployeeChangeRequest::STATUS_APPROVED, $request->refresh()->status);
        $this->assertSame($this->manager->id, $request->reviewed_by);
    }

    public function test_rejection_leaves_record_unchanged(): void
    {
        $this->actingAs($this->employeeUser);
        $this->employee->update(['nic' => '22222-2222222-2']);

        $request = EmployeeChangeRequest::firstOrFail();
        $request->reject($this->manager, 'Incorrect NIC format');

        $this->assertSame('12345-1234567-1', $this->employee->refresh()->nic);
        $this->assertSame(EmployeeChangeRequest::STATUS_REJECTED, $request->refresh()->status);
        $this->assertSame('Incorrect NIC format', $request->rejection_reason);
    }

    public function test_approved_request_cannot_be_approved_again(): void
    {
        $this->actingAs($this->employeeUser);
        $this->employee->update(['nic' => '33333-3333333-3']);

        $request = EmployeeChangeRequest::firstOrFail();
        $request->approve($this->manager);

        $this->expectException(\InvalidArgumentException::class);
        $request->approve($this->manager);
    }

    public function test_admin_edits_apply_immediately(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin-direct@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('password'), 'status' => 1]
        );
        $admin->assignRole('Administrator');

        $this->actingAs($admin);
        $this->employee->update(['nic' => '44444-4444444-4']);

        $this->assertSame('44444-4444444-4', $this->employee->refresh()->nic);
        $this->assertSame(0, EmployeeChangeRequest::count());
    }

    public function test_submitting_request_notifies_approvers_not_requester(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $ceo = $this->makeUser('CEO', 'ceo-notify@test.local');

        $this->actingAs($this->employeeUser);
        $this->employee->update(['nic' => '66666-6666666-6']);

        \Illuminate\Support\Facades\Notification::assertSentTo(
            [$this->manager, $ceo],
            \App\Notifications\EmployeeChangeRequestSubmitted::class
        );
        \Illuminate\Support\Facades\Notification::assertNotSentTo(
            $this->employeeUser,
            \App\Notifications\EmployeeChangeRequestSubmitted::class
        );
    }

    public function test_employee_cannot_sneak_non_allowed_fields(): void
    {
        $this->actingAs($this->employeeUser);

        $this->employee->update(['designation' => 'CEO', 'nic' => '55555-5555555-5']);

        $this->assertSame('Backend Developer', $this->employee->refresh()->designation);

        $request = EmployeeChangeRequest::firstOrFail();
        $this->assertArrayNotHasKey('designation', $request->requested_changes);
        $this->assertSame('55555-5555555-5', $request->requested_changes['nic']);
    }
}
