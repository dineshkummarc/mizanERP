<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Resources\EmployeeSettings\Pages\EditEmployeeSetting;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeChangeRequest;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Notifications\EmployeeChangeRequestSubmitted;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Employees may edit their own salary settings, but the edit becomes a pending
 * EmployeeChangeRequest — the figures only change once an Administrator,
 * Manager or CEO approves. Privileged roles still edit directly.
 */
class EmployeeSettingSelfEditApprovalTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    private EmployeeSetting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'settings-self@test.local')->id,
            'employee_id' => 'EMP-SET-SELF',
            'gender' => 'Male',
            'phone' => '0300-5550001',
            'secondary_phone' => '0301-5550001',
        ]);

        $this->setting = EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 200000,
            'medical_allowance' => 15000,
            'advances' => 10000,
        ]);
    }

    private function editAsEmployee(array $data): void
    {
        $this->actingAs($this->employee->user);
        $this->setCurrentTenant();

        Livewire::test(EditEmployeeSetting::class, ['record' => $this->setting->getKey()])
            ->fillForm($data)
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_an_employee_may_reach_the_edit_page_for_their_own_settings(): void
    {
        $this->actingAs($this->employee->user);
        $this->setCurrentTenant();

        $this->assertTrue($this->employee->user->can('update', $this->setting));

        Livewire::test(EditEmployeeSetting::class, ['record' => $this->setting->getKey()])
            ->assertSuccessful();
    }

    public function test_an_employee_cannot_edit_a_colleagues_settings(): void
    {
        $colleague = Employee::create([
            'user_id' => $this->makeUser('Employee', 'settings-other@test.local')->id,
            'employee_id' => 'EMP-SET-OTHER',
            'gender' => 'Male',
            'phone' => '0300-5550002',
            'secondary_phone' => '0301-5550002',
        ]);

        $theirs = EmployeeSetting::create([
            'employee_id' => $colleague->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'basic_wage' => 111000,
        ]);

        $this->actingAs($this->employee->user);

        $this->assertFalse($this->employee->user->can('update', $theirs));
    }

    public function test_a_self_edit_becomes_a_pending_request_and_leaves_the_row_alone(): void
    {
        $this->editAsEmployee(['basic_wage' => 250000, 'medical_allowance' => 20000]);

        $this->assertEquals(200000, $this->setting->fresh()->basic_wage);
        $this->assertEquals(15000, $this->setting->fresh()->medical_allowance);

        $request = EmployeeChangeRequest::sole();
        $this->assertTrue($request->isPending());
        $this->assertTrue($request->targetsSetting());
        $this->assertSame($this->setting->id, $request->target_id);
        $this->assertSame($this->employee->id, $request->employee_id);
        $this->assertEqualsCanonicalizing(
            ['basic_wage', 'medical_allowance'],
            array_keys($request->requested_changes)
        );
        $this->assertSame(250000, (int) $request->requested_changes['basic_wage']);
        $this->assertSame(200000, (int) $request->original_values['basic_wage']);
    }

    public function test_approval_writes_the_figures_onto_the_settings_row(): void
    {
        $this->editAsEmployee(['basic_wage' => 250000]);

        $approver = $this->makeUser('Administrator', 'settings-approver@test.local');
        EmployeeChangeRequest::sole()->approve($approver);

        $this->assertEquals(250000, $this->setting->fresh()->basic_wage);
        $this->assertSame(EmployeeChangeRequest::STATUS_APPROVED, EmployeeChangeRequest::sole()->status);
    }

    public function test_rejection_leaves_the_figures_untouched(): void
    {
        $this->editAsEmployee(['basic_wage' => 250000]);

        $approver = $this->makeUser('Administrator', 'settings-rejecter@test.local');
        EmployeeChangeRequest::sole()->reject($approver, 'Not budgeted.');

        $this->assertEquals(200000, $this->setting->fresh()->basic_wage);
        $this->assertSame(EmployeeChangeRequest::STATUS_REJECTED, EmployeeChangeRequest::sole()->status);
    }

    /** The period fields are not the employee's to propose. */
    public function test_an_employee_cannot_request_period_or_owner_changes(): void
    {
        $this->actingAs($this->employee->user);
        $this->setCurrentTenant();

        // Bypass the form (which disables these) to prove the model refuses too.
        $this->setting->update([
            'basic_wage' => 250000,
            'start_date' => '2026-01-01',
            'fiscal_year_id' => 999,
        ]);

        $fresh = $this->setting->fresh();
        $this->assertSame('2026-07-01', $fresh->start_date->toDateString());
        $this->assertSame($this->fiscalYear->id, $fresh->fiscal_year_id);

        $this->assertSame(
            ['basic_wage'],
            array_keys(EmployeeChangeRequest::sole()->requested_changes)
        );
    }

    public function test_a_privileged_edit_applies_immediately(): void
    {
        $admin = $this->makeUser('Administrator', 'settings-admin@test.local');
        $this->actingAs($admin);
        $this->setCurrentTenant();

        Livewire::test(EditEmployeeSetting::class, ['record' => $this->setting->getKey()])
            ->fillForm(['basic_wage' => 300000])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals(300000, $this->setting->fresh()->basic_wage);
        $this->assertSame(0, EmployeeChangeRequest::count());
    }

    /** Approvers are notified; the requester is not. */
    public function test_approvers_are_notified_of_a_settings_request(): void
    {
        Notification::fake();

        $approver = $this->makeUser('Administrator', 'settings-notified@test.local');

        $this->editAsEmployee(['basic_wage' => 250000]);

        Notification::assertSentTo(
            $approver,
            EmployeeChangeRequestSubmitted::class
        );
        Notification::assertNotSentTo(
            $this->employee->user,
            EmployeeChangeRequestSubmitted::class
        );
    }

    /** Profile requests must keep working alongside the new settings ones. */
    public function test_profile_requests_still_target_the_employee(): void
    {
        $this->actingAs($this->employee->user);
        $this->setCurrentTenant();

        $this->employee->update(['phone' => '0300-9999999']);

        $request = EmployeeChangeRequest::sole();
        $this->assertFalse($request->targetsSetting());
        $this->assertSame(EmployeeChangeRequest::TARGET_EMPLOYEE, $request->target_type);
        $this->assertSame('0300-5550001', $this->employee->fresh()->phone);

        $request->approve($this->makeUser('Administrator', 'profile-approver@test.local'));
        $this->assertSame('0300-9999999', $this->employee->fresh()->phone);
    }

    public function test_approving_a_request_whose_settings_row_is_gone_is_reported(): void
    {
        $this->editAsEmployee(['basic_wage' => 250000]);

        $request = EmployeeChangeRequest::sole();
        User::unguarded(fn () => $this->setting->delete());

        $this->expectException(\InvalidArgumentException::class);
        $request->approve($this->makeUser('Administrator', 'gone-approver@test.local'));
    }
}
