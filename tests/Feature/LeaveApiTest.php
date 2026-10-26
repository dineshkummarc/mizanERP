<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Models\LeaveEntitlement;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Support\TenantStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The employee's own leave over the API — docs/hrms-plan.md §11's "obvious next
 * endpoints" after /my-payslips.
 *
 * Two promises worth holding: the balance is the same arithmetic the panel shows
 * (opening + carried + accrued + adjustments − taken, per counted type), and filing
 * goes through LeaveRequestService, so the API cannot file what the form would
 * refuse — and always files for the caller, whatever the payload claims.
 */
class LeaveApiTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    private LeaveType $annual;

    private LeaveType $casual;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->actingAs($this->makeUser('Administrator', 'leave-api-admin@test.local'));
        $this->setCurrentTenant();

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'leave-api@test.local')->id,
            'employee_id' => 'EMP-LV',
            'gender' => 'Male',
            'phone' => '0300-0000010',
            'is_active' => true,
            'date_of_joining' => '2020-01-01',
        ]);

        // Two counted types (two rows, so a lazy-load guard would actually arm)
        // with the current year open for both.
        $this->annual = LeaveType::create([
            'code' => 'annual', 'label' => 'Annual Leave', 'days_per_year' => 14,
            'accrual_method' => LeaveType::ACCRUAL_ANNUAL_UPFRONT,
        ]);
        $this->casual = LeaveType::create([
            'code' => 'casual', 'label' => 'Casual Leave', 'days_per_year' => 10,
            'accrual_method' => LeaveType::ACCRUAL_ANNUAL_UPFRONT, 'sort' => 1,
        ]);

        foreach ([[$this->annual, 14], [$this->casual, 10]] as [$type, $days]) {
            LeaveEntitlement::create([
                'employee_id' => $this->employee->id,
                'leave_type_id' => $type->id,
                'leave_year_start' => Carbon::now()->startOfYear()->toDateString(),
                'leave_year_end' => Carbon::now()->endOfYear()->toDateString(),
                'accrued_days' => $days,
            ]);
        }
    }

    /** The first two weekdays of the current year's given ISO week, as dates. */
    private function weekdays(int $week): array
    {
        $monday = Carbon::now()->startOfYear()->addWeeks($week)->next(Carbon::MONDAY);

        return [$monday->toDateString(), $monday->copy()->addDay()->toDateString()];
    }

    public function test_balances_carry_the_arithmetic_per_counted_type(): void
    {
        // Sick leave that is not rationed has no balance and must not appear as
        // "0 left".
        LeaveType::create([
            'code' => 'sick', 'label' => 'Sick Leave',
            'accrual_method' => LeaveType::ACCRUAL_UNLIMITED,
        ]);

        // Two approved days against annual, through the same service the panel uses.
        [$monday, $tuesday] = $this->weekdays(2);
        $request = app(LeaveRequestService::class)->submit(new LeaveRequest([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->annual->id,
            'from_date' => $monday,
            'to_date' => $tuesday,
        ]), auth()->user());
        app(LeaveRequestService::class)->approve($request, $this->makeUser('Administrator', 'leave-api-approver@test.local'));

        $this->actingAs($this->employee->user);

        $response = $this->getJson('/api/my-leave-balances')
            ->assertOk()
            ->assertJsonPath('count', 2);

        $annual = collect($response->json('data'))->firstWhere('code', 'annual');
        $this->assertTrue($annual['opened']);
        $this->assertSame(14.0, (float) $annual['credited']);
        $this->assertSame(2.0, (float) $annual['taken']);
        $this->assertSame(12.0, (float) $annual['remaining']);

        $casual = collect($response->json('data'))->firstWhere('code', 'casual');
        $this->assertSame(10.0, (float) $casual['remaining']);

        $this->assertNull(collect($response->json('data'))->firstWhere('code', 'sick'));
    }

    public function test_a_caller_without_an_employee_profile_gets_404(): void
    {
        // Already acting as the administrator, who has no employee record.
        $this->getJson('/api/my-leave-balances')->assertNotFound();
        $this->postJson('/api/my-leave-requests', [])->assertNotFound();
    }

    public function test_applying_files_a_pending_request_for_the_caller_only(): void
    {
        $colleague = Employee::create([
            'employee_id' => 'EMP-LV2',
            'gender' => 'Male',
            'phone' => '0300-0000011',
            'is_active' => true,
        ]);

        [$monday, $tuesday] = $this->weekdays(4);

        $this->actingAs($this->employee->user);

        $this->postJson('/api/my-leave-requests', [
            'leave_type_id' => $this->annual->id,
            'from_date' => $monday,
            'to_date' => $tuesday,
            'reason' => 'Family wedding',
            // Filing for a colleague is an HR act and stays in the panel: this
            // must be ignored, not honoured.
            'employee_id' => $colleague->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_PENDING)
            // Whole day counts serialize as JSON integers (2, not 2.0); the
            // half-day test below pins the fractional case.
            ->assertJsonPath('data.days', 2);

        $filed = LeaveRequest::latest('id')->first();
        $this->assertSame($this->employee->id, $filed->employee_id);
        $this->assertSame($this->employee->user_id, $filed->submitted_by);
    }

    public function test_a_half_day_costs_half_a_day(): void
    {
        [$monday] = $this->weekdays(6);

        $this->actingAs($this->employee->user);

        $this->postJson('/api/my-leave-requests', [
            'leave_type_id' => $this->annual->id,
            'from_date' => $monday,
            'to_date' => $monday,
            'is_half_day' => true,
            'half_day_period' => LeaveRequest::HALF_FIRST,
        ])
            ->assertCreated()
            ->assertJsonPath('data.days', 0.5);
    }

    public function test_the_service_refusals_come_back_as_422(): void
    {
        [$monday, $tuesday] = $this->weekdays(8);

        $this->actingAs($this->employee->user);

        $file = fn () => $this->postJson('/api/my-leave-requests', [
            'leave_type_id' => $this->annual->id,
            'from_date' => $monday,
            'to_date' => $tuesday,
        ]);

        $file()->assertCreated();

        // The overlap refusal the form gets, with the same wording.
        $response = $file()->assertStatus(422);
        $this->assertStringContainsString('overlaps', $response->json('message'));
    }

    public function test_validation_holds_the_obvious_lines(): void
    {
        $this->actingAs($this->employee->user);

        $this->postJson('/api/my-leave-requests', [
            'leave_type_id' => $this->annual->id,
            // no dates
        ])->assertStatus(422)->assertJsonValidationErrors(['from_date', 'to_date']);

        [$monday] = $this->weekdays(10);

        // A half day must say which half.
        $this->postJson('/api/my-leave-requests', [
            'leave_type_id' => $this->annual->id,
            'from_date' => $monday,
            'to_date' => $monday,
            'is_half_day' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['half_day_period']);

        // A type that no longer exists (or is inactive) is a validation error,
        // not a 500.
        $this->postJson('/api/my-leave-requests', [
            'leave_type_id' => 999999,
            'from_date' => $monday,
            'to_date' => $monday,
        ])->assertStatus(422)->assertJsonValidationErrors(['leave_type_id']);
    }

    public function test_a_type_requiring_a_document_needs_one_attached(): void
    {
        $sick = LeaveType::create([
            'code' => 'medical', 'label' => 'Medical Leave', 'days_per_year' => 8,
            'accrual_method' => LeaveType::ACCRUAL_ANNUAL_UPFRONT,
            'requires_document' => true,
        ]);

        [$monday] = $this->weekdays(12);

        $this->actingAs($this->employee->user);

        // Without one it is refused rather than quietly filed undocumented.
        $this->postJson('/api/my-leave-requests', [
            'leave_type_id' => $sick->id,
            'from_date' => $monday,
            'to_date' => $monday,
        ])->assertStatus(422)->assertJsonValidationErrors(['document']);

        // With one — multipart, a certificate photographed on the phone — it is stored where the form
        // stores it, under the company's own directory.
        $this->post('/api/my-leave-requests', [
            'leave_type_id' => $sick->id,
            'from_date' => $monday,
            'to_date' => $monday,
            'document' => UploadedFile::fake()->image('certificate.png'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $request = LeaveRequest::query()->where('leave_type_id', $sick->id)->firstOrFail();
        $this->assertStringStartsWith('leave-requests/', $request->document_path);
        $this->assertTrue(TenantStorage::publicDisk($this->tenant)->exists($request->document_path));
    }
}
