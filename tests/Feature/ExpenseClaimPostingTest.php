<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Expenses\Models\ExpenseClaim;
use App\Modules\Payroll\Models\Payslip;
use App\Support\ModuleMap;
use App\Support\TenantSettings;
use Database\Seeders\TransactionTypeSeeder;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * An approved claim in an account — `docs/bexio-gap-plan.md` Phase 3, item 13.
 *
 * The assertion that carries it is the last: the payslip's reimbursement line debits the same account
 * approval credited, so a claim approved in March and paid in April is a liability for exactly the
 * month it was one, and an expense in its own category rather than in a shared one.
 */
class ExpenseClaimPostingTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $approver;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->approver = $this->makeUser('Administrator', 'approver@test.local');
        $this->actingAs($this->approver);
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'expenses', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->seed(TransactionTypeSeeder::class);
        Notification::fake();

        $claimant = $this->makeUser('Employee', 'claimant@test.local');
        $claimant->update(['name' => 'Nadeem Yahya']);

        $this->employee = Employee::create([
            'user_id' => $claimant->id,
            'employee_id' => 'EMP-CLAIM',
            'gender' => 'Male',
        ]);

        EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 400000,
        ]);
    }

    public function test_approving_a_claim_books_the_expense_in_its_category_and_the_liability(): void
    {
        $claim = $this->claim(5000, ['transaction_type_id' => TransactionType::byCode('fuel')->getKey()]);

        $claim->approve($this->approver);

        $entry = $claim->fresh()->journalEntry;

        $this->assertNotNull($entry, 'approval posts');
        $this->assertTrue((bool) $entry->is_posted);
        $this->assertSame(ModuleMap::alias(ExpenseClaim::class), $entry->source_type);
        $this->assertSame(5000.0, $this->debits($entry, '5800'), 'fuel, the claim\'s own category');
        $this->assertSame(5000.0, $this->credits($entry, '2310'), 'owed until reimbursed');
        $this->assertStringContainsString('Nadeem Yahya', $entry->memo);
    }

    public function test_a_claim_with_no_category_is_booked_as_miscellaneous(): void
    {
        $claim = $this->claim(1200);

        $claim->approve($this->approver);

        $this->assertSame(1200.0, $this->debits($claim->fresh()->journalEntry, '5900'));
    }

    /** Status and posting are one transaction: a claim that cannot be booked is not approved either. */
    public function test_a_claim_that_cannot_be_booked_is_not_approved(): void
    {
        // Point the reimbursement line at an account that does not exist, which is the misconfiguration
        // PayrollAccounts refuses to post through.
        app(TenantSettings::class)->set('accounting.payroll_accounts', ['expense_reimbursement' => '9999']);
        $claim = $this->claim(1200);

        try {
            $claim->approve($this->approver);
            $this->fail('Nothing to book to should have refused the approval.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not exist', $e->getMessage());
        }

        $this->assertSame(ExpenseClaim::STATUS_PENDING, $claim->fresh()->status);
        $this->assertNull($claim->fresh()->journal_entry_id);
    }

    public function test_deleting_an_approved_claim_takes_the_accrual_back(): void
    {
        $claim = $this->claim(5000, ['transaction_type_id' => TransactionType::byCode('fuel')->getKey()]);
        $claim->approve($this->approver);

        $this->assertSame(5000.0, $this->balance('2310'));

        $claim->delete();

        $this->assertSame(0.0, $this->balance('2310'));
        $this->assertSame(0.0, $this->balance('5800'));
    }

    /** The payslip's reimbursement line clears exactly what approval accrued. */
    public function test_the_payslip_that_reimburses_the_claim_clears_the_liability(): void
    {
        $claim = $this->claim(5000, ['transaction_type_id' => TransactionType::byCode('fuel')->getKey()]);
        $claim->approve($this->approver);

        $payslip = Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'August',
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);

        $this->assertSame(5000.0, (float) $payslip->fresh()->expense_reimbursement);
        $this->assertSame(ExpenseClaim::STATUS_SETTLED, $claim->fresh()->status);

        $entry = JournalEntry::forSource(Payslip::class, $payslip->getKey())->firstOrFail();

        $this->assertSame(5000.0, $this->debits($entry, '2310'), 'the payslip debits the liability, not an expense');
        $this->assertSame(0.0, $this->debits($entry, '5600'));
    }

    /** @param  array<string, mixed>  $attributes */
    private function claim(float $amount, array $attributes = []): ExpenseClaim
    {
        return ExpenseClaim::create(array_merge([
            'employee_id' => $this->employee->id,
            'claimed_on' => '2026-08-02',
            'description' => 'Fuel to the client site',
            'amount' => $amount,
            'submitted_by' => $this->employee->user_id,
        ], $attributes));
    }

    private function debits(JournalEntry $entry, string $code): float
    {
        return round((float) $entry->lines()->where('account_id', $this->accountId($code))->sum('debit_amount'), 2);
    }

    private function credits(JournalEntry $entry, string $code): float
    {
        return round((float) $entry->lines()->where('account_id', $this->accountId($code))->sum('credit_amount'), 2);
    }

    /** Credits less debits over posted lines — what a liability account is carrying. */
    private function balance(string $code): float
    {
        $lines = JournalEntryLine::query()
            ->where('account_id', $this->accountId($code))
            ->whereHas('journalEntry', fn ($q) => $q->where('is_posted', true));

        $credits = (float) (clone $lines)->sum('credit_amount');
        $debits = (float) (clone $lines)->sum('debit_amount');

        return round(abs($credits - $debits), 2);
    }

    private function accountId(string $code): int
    {
        return Account::where('code', $code)->firstOrFail()->getKey();
    }
}
