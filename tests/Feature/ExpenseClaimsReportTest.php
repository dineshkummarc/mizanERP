<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Modules\Expenses\Filament\Pages\ExpenseClaimsReport;
use App\Modules\Expenses\Models\ExpenseClaim;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Expense Claims — `docs/reports-expansion-plan.md` Phase 2.8.
 *
 * The report's value is a figure nothing else in the application states: what has been approved and not yet
 * paid, which approval now books as a liability. So the tests are about that figure and about the two things a
 * reader could otherwise misread —
 *
 *  - **the period is the *financial* year**, not the calendar one, which is the mistake `ReportPeriod` exists
 *    to prevent and the one a claims report would make most naturally;
 *  - **the liability is a balance, not a period**, so a claim approved before the window still counts. Those
 *    two rules pull in opposite directions and a report that applied one to both figures would be wrong in
 *    one of them.
 *
 * The liability is in an account since docs/bexio-gap-plan.md Phase 3 — approval accrues it to the account
 * `expense_reimbursement` maps, and the payslip clears it there — so the note names that account rather than
 * explaining an absence. ExpenseClaimPostingTest covers the posting itself; this file covers the figures.
 */
class ExpenseClaimsReportTest extends AccountingTestCase
{
    use InteractsWithTenant;

    /** Inside the 2026-2027 fiscal year, and in its second half so the calendar year differs. */
    private const AS_OF = '2027-02-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'claims@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'accounting', 'expenses'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true],
        );
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function employee(string $code): Employee
    {
        return Employee::create([
            'employee_id' => $code,
            'name' => $code,
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2020-01-01',
        ]);
    }

    /**
     * A claim in a given state.
     *
     * Written with `withoutEvents`, because creating a claim notifies every approver and this file is about
     * arithmetic rather than about who gets told.
     */
    private function claim(Employee $employee, float $amount, string $status, string $claimedOn = '2026-08-10'): ExpenseClaim
    {
        return ExpenseClaim::withoutEvents(fn (): ExpenseClaim => ExpenseClaim::create([
            'employee_id' => $employee->id,
            // Not nullable: a claim is always submitted by somebody, which is the whole basis of the rule
            // that an approver must be somebody else.
            'submitted_by' => auth()->id(),
            'claimed_on' => $claimedOn,
            'description' => 'Taxi to the airport',
            'amount' => $amount,
            'status' => $status,
        ]));
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('ExpenseClaimsReport', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for ExpenseClaimsReport');

        return $payload;
    }

    private function row(array $payload, string $code): array
    {
        foreach ($payload['rows'] as $row) {
            if (str_contains($row[0], $code)) {
                return $row;
            }
        }

        $this->fail("no row for [{$code}] in ".collect($payload['rows'])->flatten()->implode(' | '));
    }

    // ────────────────────────────────────────────────────── the liability ──

    /** Approved and unpaid is the headline, and the note says which account now carries it. */
    public function test_the_headline_is_what_is_approved_and_unpaid(): void
    {
        $employee = $this->employee('EMP-1');
        $this->claim($employee, 5_000, ExpenseClaim::STATUS_APPROVED);
        $this->claim($employee, 3_000, ExpenseClaim::STATUS_SETTLED);
        $this->claim($employee, 1_000, ExpenseClaim::STATUS_PENDING);

        $payload = $this->report();

        $this->assertSame(5_000.0, $payload['tiles'][0]['value'], 'only the approved-and-unpaid');
        $this->assertSame(3_000.0, $payload['tiles'][1]['value'], 'and what has actually been reimbursed');
        $this->assertStringContainsString('ACCRUED IN 2310 UNTIL REIMBURSED', $payload['note']);
    }

    /**
     * The liability is a balance: a claim approved before the window still counts.
     *
     * The one place this report deliberately ignores its own period. Money owed does not stop being owed
     * because the year rolled over, and a liability that reset every 1 July would understate what the
     * company owes for eleven months of the year.
     */
    public function test_the_liability_counts_claims_from_before_the_period(): void
    {
        $employee = $this->employee('EMP-1');
        // Last fiscal year, still approved and unpaid.
        $this->claim($employee, 7_000, ExpenseClaim::STATUS_APPROVED, '2026-03-01');
        $this->claim($employee, 2_000, ExpenseClaim::STATUS_APPROVED, '2026-08-10');

        $payload = $this->report();

        $this->assertSame(9_000.0, $payload['tiles'][0]['value'], 'both are still owed');
        // But only this year's claim is in the rows, which are the period.
        $this->assertSame('2,000', $this->row($payload, 'EMP-1')[3]);
    }

    /** Nothing owed says so rather than showing a nought against a liability. */
    public function test_nothing_owed_is_stated(): void
    {
        $this->claim($this->employee('EMP-1'), 3_000, ExpenseClaim::STATUS_SETTLED);

        $this->assertStringContainsString('NOTHING APPROVED IS UNPAID', $this->report()['note']);
    }

    // ────────────────────────────────────────────────────────── the period ──

    /**
     * The financial year, not the calendar year.
     *
     * Read in February, a calendar-year window would start on 1 January and drop seven months of a company's
     * expenses. `ReportPeriod` exists for exactly this and the plan says none of these reports may call
     * `startOfYear()`.
     */
    public function test_the_period_is_the_financial_year(): void
    {
        $employee = $this->employee('EMP-1');
        // August 2026: inside the fiscal year, outside the calendar year of the date read.
        $this->claim($employee, 6_000, ExpenseClaim::STATUS_SETTLED, '2026-08-10');
        // Before the fiscal year began.
        $this->claim($employee, 9_000, ExpenseClaim::STATUS_SETTLED, '2026-06-15');

        $payload = $this->report();

        $this->assertStringContainsString('2026-07-01', $payload['subtitle']);
        $this->assertSame(6_000.0, $payload['tiles'][1]['value'], 'August is in, June is not');
    }

    /** A claim is dated by when it was claimed, so a slow approval cannot move it between years. */
    public function test_a_claim_belongs_to_the_period_it_was_claimed_in(): void
    {
        $employee = $this->employee('EMP-1');
        $claim = $this->claim($employee, 4_000, ExpenseClaim::STATUS_SETTLED, '2026-08-10');

        // Decided much later — which must not move it.
        $claim->forceFill(['decided_at' => '2027-05-01'])->save();

        $this->assertSame(4_000.0, $this->report()['tiles'][1]['value']);
    }

    // ────────────────────────────────────────────────── how it is stated ──

    /** Every state has its own column, refused included. */
    public function test_each_state_has_its_own_column(): void
    {
        $employee = $this->employee('EMP-1');
        $this->claim($employee, 1_000, ExpenseClaim::STATUS_PENDING);
        $this->claim($employee, 2_000, ExpenseClaim::STATUS_APPROVED);
        $this->claim($employee, 3_000, ExpenseClaim::STATUS_SETTLED);
        $this->claim($employee, 4_000, ExpenseClaim::STATUS_REFUSED);

        $row = $this->row($this->report(), 'EMP-1');

        $this->assertSame('4', $row[1], 'four claims');
        $this->assertSame('1,000', $row[2]);
        $this->assertSame('2,000', $row[3]);
        $this->assertSame('3,000', $row[4]);
        $this->assertSame('4,000', $row[5]);
    }

    /** A state nobody is in reads as a dash, so it is possible to see who is actually waiting. */
    public function test_a_state_with_nothing_in_it_reads_as_a_dash(): void
    {
        $this->claim($this->employee('EMP-1'), 3_000, ExpenseClaim::STATUS_SETTLED);

        $row = $this->row($this->report(), 'EMP-1');

        $this->assertSame('—', $row[2], 'nothing pending');
        $this->assertSame('—', $row[3], 'nothing approved and unpaid');
        $this->assertSame('3,000', $row[4]);
    }

    /**
     * Rows are ordered by what has been reimbursed, and sorted before they are formatted.
     *
     * The first version sorted the finished rows, comparing "9,000" against "12,000" as text and putting the
     * larger figure second. Formatting is the last thing that happens to a number for exactly this reason.
     */
    public function test_rows_are_ordered_by_what_was_reimbursed(): void
    {
        $this->claim($this->employee('EMP-SMALL'), 9_000, ExpenseClaim::STATUS_SETTLED);
        $this->claim($this->employee('EMP-BIG'), 12_000, ExpenseClaim::STATUS_SETTLED);

        $payload = $this->report();

        $this->assertStringContainsString('EMP-BIG', $payload['rows'][0][0]);
        $this->assertStringContainsString('EMP-SMALL', $payload['rows'][1][0]);
    }

    /**
     * The report says where the liability sits, rather than leaving the figure to be read as unbooked.
     *
     * This used to assert the opposite — that reimbursements shared an account with meal recovery and so
     * could not be reconciled, with the two mappings pinned to one code "if these are ever separated, the
     * report can and should reconcile". They are separated: `docs/bexio-gap-plan.md` Phase 3 accrues an
     * approved claim to the account `expense_reimbursement` maps, and the payslip clears it there.
     */
    public function test_it_says_which_account_carries_the_liability(): void
    {
        $this->claim($this->employee('EMP-1'), 3_000, ExpenseClaim::STATUS_APPROVED);

        $note = $this->report()['note'];

        $this->assertStringContainsString('ACCRUED IN 2310', $note);
        $this->assertStringContainsString('REIMBURSEMENTS CLEAR 2310', $note);

        // And the premise: reimbursements no longer share meal recovery's code.
        $this->assertNotSame(
            config('accounting.payroll_accounts.meal_recovery'),
            config('accounting.payroll_accounts.expense_reimbursement'),
        );
    }

    /** A period with no claims says so. */
    public function test_a_period_with_no_claims_says_so(): void
    {
        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NO CLAIM WAS MADE IN THIS PERIOD', $payload['note']);
    }

    /** The record row foots the rows above it. */
    public function test_the_record_row_foots_the_rows(): void
    {
        $one = $this->employee('EMP-1');
        $two = $this->employee('EMP-2');
        $this->claim($one, 1_000, ExpenseClaim::STATUS_PENDING);
        $this->claim($one, 3_000, ExpenseClaim::STATUS_SETTLED);
        $this->claim($two, 5_000, ExpenseClaim::STATUS_SETTLED);

        $payload = $this->report();

        foreach ([2, 4] as $column) {
            $rows = array_sum(array_map(
                fn (array $row): float => (float) str_replace([',', '—'], ['', '0'], $row[$column]),
                $payload['rows'],
            ));

            $this->assertSame(
                (float) str_replace(',', '', $payload['footer'][$column]),
                $rows,
                "column {$column} does not add up the rows above it",
            );
        }
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->claim($this->employee('EMP-1'), 3_000, ExpenseClaim::STATUS_APPROVED);

        $onThePage = Livewire::test(ExpenseClaimsReport::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame($onThePage, app(ReportPaneRenderer::class)->for('ExpenseClaimsReport', self::AS_OF, false, []));
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertTrue(ExpenseClaimsReport::canAccess(), 'an administrator should be able to open it');

        $this->actingAs(\App\Modules\Core\Models\User::factory()->create(['status' => 1]));

        $this->assertFalse(ExpenseClaimsReport::canAccess());
    }
}
