<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\Payslip;
use App\Support\Impersonation;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Accepting a payslip is a statement of consent, and an administrator signed in as
 * the employee can enter it for staff who cannot do it themselves.
 *
 * The audit log already records who was really at the keyboard, but the payslip is
 * the document anybody actually reads — so it carries the note itself. An accepted
 * payslip that stays silent about having been accepted by somebody else is a claim
 * the record cannot support.
 */
class PayslipReviewOnBehalfTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $admin;

    private User $employeeUser;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('Administrator', 'on-behalf-admin@test.local');
        $this->employeeUser = $this->makeUser('Employee', 'on-behalf-employee@test.local');

        $this->employee = Employee::create([
            'user_id' => $this->employeeUser->id,
            'employee_id' => 'EMP-BEHALF',
            'gender' => 'Male',
            'phone' => '0300-0000000',
            'is_active' => 1,
        ]);

        EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 200000,
        ]);

        // Filament::setTenant dispatches TenantSet with the current user, so
        // somebody has to be signed in before the tenant can be set.
        $this->actingAs($this->admin);

        $company = $this->setCurrentTenant();
        $company->users()->syncWithoutDetaching([$this->admin->getKey(), $this->employeeUser->getKey()]);
    }

    private function payslip(string $month = 'July'): Payslip
    {
        return Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => $month,
            'total_working_days' => 22,
            'paid_days' => 22,
            'basic_wage' => 200000,
            'net_salary' => 180000,
        ]);
    }

    public function test_an_employees_own_acceptance_carries_no_note(): void
    {
        $payslip = $this->payslip();

        $this->actingAs($this->employeeUser);
        $payslip->recordEmployeeReview(Payslip::REVIEW_ACCEPTED);

        $payslip->refresh();

        $this->assertSame(Payslip::REVIEW_ACCEPTED, $payslip->employee_review);
        $this->assertFalse($payslip->reviewWasRecordedOnBehalf());
        $this->assertNull($payslip->reviewOnBehalfNote());
        $this->assertNull($payslip->employee_review_recorded_by);
    }

    public function test_an_acceptance_entered_while_impersonating_names_who_entered_it(): void
    {
        $payslip = $this->payslip();

        $this->actingAs($this->admin);
        app(Impersonation::class)->start($this->employeeUser);

        $payslip->recordEmployeeReview(Payslip::REVIEW_ACCEPTED);

        $payslip->refresh();

        $this->assertTrue($payslip->reviewWasRecordedOnBehalf());
        $this->assertSame($this->admin->getKey(), $payslip->employee_review_recorded_by);
        $this->assertSame($this->admin->name, $payslip->employee_review_recorded_by_name);

        $note = $payslip->reviewOnBehalfNote();
        $this->assertStringContainsString('Accepted on behalf of the employee', $note);
        $this->assertStringContainsString($this->admin->name, $note);
    }

    public function test_a_rejection_entered_on_behalf_says_rejected(): void
    {
        $payslip = $this->payslip('August');

        $this->actingAs($this->admin);
        app(Impersonation::class)->start($this->employeeUser);

        $payslip->recordEmployeeReview(Payslip::REVIEW_REJECTED, 'Overtime missing');

        $this->assertStringContainsString('Rejected on behalf of the employee', $payslip->refresh()->reviewOnBehalfNote());
    }

    /**
     * The name is snapshotted rather than joined: `users` is in the landlord
     * database and `payslips` is per-company, so the note has to read without a
     * cross-database lookup — and has to survive the account going away.
     */
    public function test_the_note_survives_the_administrator_being_deleted(): void
    {
        $payslip = $this->payslip();

        $this->actingAs($this->admin);
        app(Impersonation::class)->start($this->employeeUser);
        $payslip->recordEmployeeReview(Payslip::REVIEW_ACCEPTED);

        $name = $this->admin->name;
        app(Impersonation::class)->stop();
        $this->admin->delete();

        $payslip->refresh();

        $this->assertTrue($payslip->reviewWasRecordedOnBehalf());
        $this->assertStringContainsString($name, $payslip->reviewOnBehalfNote());
    }

    public function test_the_payslip_document_states_it(): void
    {
        $payslip = $this->payslip();

        $this->actingAs($this->admin);
        app(Impersonation::class)->start($this->employeeUser);
        $payslip->recordEmployeeReview(Payslip::REVIEW_ACCEPTED);

        $html = view('pdfs.payslip', ['data' => $payslip->refresh()])->render();

        $this->assertStringContainsString('on behalf of the employee', $html);
        $this->assertStringContainsString('Not signed by the employee', $html);
        $this->assertStringContainsString($this->admin->name, $html);
    }

    public function test_the_document_stays_clean_for_a_normal_acceptance(): void
    {
        $payslip = $this->payslip();

        $this->actingAs($this->employeeUser);
        $payslip->recordEmployeeReview(Payslip::REVIEW_ACCEPTED);

        $html = view('pdfs.payslip', ['data' => $payslip->refresh()])->render();

        $this->assertStringNotContainsString('on behalf of the employee', $html);
        $this->assertStringNotContainsString('Not signed by the employee', $html);
    }

    /**
     * The columns had to join the review-only set, or entering an acknowledgement
     * would stop counting as a review-only change and re-run the tax sync and the
     * ledger posting for a payslip whose figures nobody touched.
     */
    public function test_recording_on_behalf_is_still_a_review_only_change(): void
    {
        $payslip = $this->payslip();
        $postedBefore = \App\Modules\Accounting\Models\JournalEntry::where('source_type', Payslip::class)
            ->where('source_id', $payslip->getKey())
            ->count();

        $this->actingAs($this->admin);
        app(Impersonation::class)->start($this->employeeUser);
        $payslip->recordEmployeeReview(Payslip::REVIEW_ACCEPTED);

        $this->assertSame(
            $postedBefore,
            \App\Modules\Accounting\Models\JournalEntry::where('source_type', Payslip::class)
                ->where('source_id', $payslip->getKey())
                ->count(),
            'An acknowledgement must not re-post the payroll entry.'
        );
    }
}
