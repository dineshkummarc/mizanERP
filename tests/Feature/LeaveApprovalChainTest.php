<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Models\LeaveEntitlement;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Notifications\LeaveRequestDecided;
use App\Modules\Leave\Notifications\LeaveRequestSubmitted;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Support\TenantSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Multi-step leave approval — docs/hrms-plan.md §11: manager, then the roles the company names, in order.
 *
 * The cases that matter: an empty chain changes nothing; each step waits for its role and tells its
 * holders; only the last approval records the days and tells the employee; a refusal or the segregation
 * rule holds at every step.
 */
class LeaveApprovalChainTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $admin;

    private User $ceo;

    private Employee $employee;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->admin = $this->makeUser('Administrator', 'admin@test.local');
        $this->actingAs($this->admin);
        $this->setCurrentTenant();

        $this->ceo = $this->makeUser('CEO', 'ceo@test.local');
        $this->ceo->givePermissionTo('LeaveRequestApprove');

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'staff@test.local')->id,
            'employee_id' => 'EMP-CH',
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2020-01-01',
        ]);

        $this->annual = LeaveType::create([
            'code' => 'annual', 'label' => 'Annual Leave', 'days_per_year' => 14,
            'accrual_method' => LeaveType::ACCRUAL_ANNUAL_UPFRONT,
        ]);

        LeaveEntitlement::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->annual->id,
            'leave_year_start' => Carbon::now()->startOfYear()->toDateString(),
            'leave_year_end' => Carbon::now()->endOfYear()->toDateString(),
            'accrued_days' => 14,
        ]);
    }

    private function chain(array $roles): void
    {
        app(TenantSettings::class)->set('leave.approval_chain', $roles);
    }

    private function request(int $week = 10): LeaveRequest
    {
        $monday = Carbon::now()->startOfYear()->addWeeks($week)->next(Carbon::MONDAY);

        $request = new LeaveRequest([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->annual->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->copy()->addDay()->toDateString(),
        ]);

        return app(LeaveRequestService::class)->submit($request, $this->employee->user);
    }

    public function test_with_no_chain_one_approval_decides_as_before(): void
    {
        $request = $this->request();

        app(LeaveRequestService::class)->approve($request, $this->admin);

        $this->assertTrue($request->fresh()->isApproved());
        $this->assertSame(0, $request->approvals()->count());
        $this->assertGreaterThan(0, $request->days()->count());
        $this->assertNull($request->fresh()->awaitingLabel());
    }

    public function test_a_chain_waits_for_each_role_in_turn_and_only_the_last_approval_decides(): void
    {
        $this->chain(['CEO']);
        $request = $this->request();
        $manager = $this->makeUser('Manager', 'pm@test.local');
        $manager->givePermissionTo('LeaveRequestApprove');

        // The first approval is anybody's who may decide; it records itself and tells the CEO.
        app(LeaveRequestService::class)->approve($request, $manager);

        $request->refresh();
        $this->assertTrue($request->isPending());
        $this->assertSame(1, $request->approvals()->count());
        $this->assertSame(0, $request->days()->count(), 'no days until the last approval');
        $this->assertStringContainsString('waiting for CEO', $request->awaitingLabel());
        Notification::assertSentTo($this->ceo, LeaveRequestSubmitted::class, fn (LeaveRequestSubmitted $n): bool => $n->awaiting === 'CEO');
        Notification::assertNotSentTo($this->employee->user, LeaveRequestDecided::class);

        // The next step belongs to the CEO: the manager may not take it, and the policy says so too.
        $this->assertFalse($manager->can('decide', $request));
        $this->assertTrue($this->ceo->can('decide', $request));

        try {
            app(LeaveRequestService::class)->approve($request, $manager);
            $this->fail('A second approval by somebody without the CEO role should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('waiting for somebody holding the CEO role', $e->getMessage());
        }

        app(LeaveRequestService::class)->approve($request, $this->ceo);

        $request->refresh();
        $this->assertTrue($request->isApproved());
        $this->assertSame($this->ceo->getKey(), $request->decided_by);
        $this->assertSame([1, 2], $request->approvals()->pluck('step')->all());
        $this->assertSame([null, 'CEO'], $request->approvals()->pluck('role')->all());
        $this->assertGreaterThan(0, $request->days()->count());
        Notification::assertSentTo($this->employee->user, LeaveRequestDecided::class);
    }

    public function test_a_refusal_at_a_later_step_refuses_the_request(): void
    {
        $this->chain(['CEO']);
        $request = $this->request();

        app(LeaveRequestService::class)->approve($request, $this->admin);
        app(LeaveRequestService::class)->refuse($request, $this->ceo, 'Not during the audit.');

        $this->assertSame(LeaveRequest::STATUS_REFUSED, $request->fresh()->status);
        $this->assertSame(0, $request->days()->count());
    }

    public function test_the_segregation_rule_holds_at_every_step(): void
    {
        $this->chain(['CEO']);
        // The employee holds CEO themselves: step two would be their own leave.
        $this->employee->user->assignRole('CEO');
        $this->employee->user->givePermissionTo('LeaveRequestApprove');
        $request = $this->request();

        app(LeaveRequestService::class)->approve($request, $this->admin);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be approved by the person who filed it');

        app(LeaveRequestService::class)->approve($request, $this->employee->user->fresh());
    }

    public function test_a_two_role_chain_runs_in_the_order_named(): void
    {
        $this->chain(['Manager', 'CEO']);
        $request = $this->request();
        $manager = $this->makeUser('Manager', 'pm@test.local');
        $manager->givePermissionTo('LeaveRequestApprove');

        app(LeaveRequestService::class)->approve($request, $this->admin);
        $this->assertStringContainsString('waiting for Manager', $request->fresh()->awaitingLabel());

        app(LeaveRequestService::class)->approve($request, $manager);
        $this->assertStringContainsString('waiting for CEO', $request->fresh()->awaitingLabel());

        app(LeaveRequestService::class)->approve($request, $this->ceo);
        $this->assertTrue($request->fresh()->isApproved());
        $this->assertSame([null, 'Manager', 'CEO'], $request->approvals()->pluck('role')->all());
    }
}
