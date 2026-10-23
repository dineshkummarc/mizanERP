<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\BankPaymentFile;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\CompanyBankAccount;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Services\PaymentService;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Expenses\Filament\Resources\ExpenseClaims\Pages\ListExpenseClaims;
use App\Modules\Expenses\Models\ExpenseClaim;
use App\Modules\Expenses\Services\ExpenseClaimBankPayment;
use App\Modules\Expenses\Services\ExpenseClaimService;
use App\Modules\Payroll\Models\Payslip;
use Database\Seeders\TransactionTypeSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * An approved claim paid by bank transfer — `docs/bexio-gap-plan.md` §3.6.
 *
 * The two that carry it: the claim leaves the payroll pool the moment a payment is raised (never paid
 * twice), and approving the payment clears exactly the liability approval booked (never expensed twice).
 */
class ExpenseClaimBankPaymentTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $approver;

    private Employee $employee;

    private CompanyBankAccount $account;

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

        // A Meezan IBAN: an own-bank payee with only an IBAN is held back from the file, correctly.
        $this->employee = Employee::create([
            'user_id' => $claimant->id,
            'employee_id' => 'EMP-CLAIM',
            'gender' => 'Male',
            'iban_no' => 'PK36MEZN0000001123456702',
        ]);

        EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 400000,
        ]);

        $this->account = CompanyBankAccount::create(['title' => 'Main', 'account_no' => '0101', 'is_active' => true, 'is_default' => true]);
    }

    private function approvedClaim(float $amount = 5000): ExpenseClaim
    {
        $claim = ExpenseClaim::create([
            'employee_id' => $this->employee->id,
            'transaction_type_id' => TransactionType::byCode('fuel')->getKey(),
            'claimed_on' => '2026-10-02',
            'description' => 'Fuel to the client site',
            'amount' => $amount,
            'submitted_by' => $this->employee->user_id,
        ]);

        return $claim->approve($this->approver)->fresh();
    }

    public function test_raising_a_payment_takes_the_claim_out_of_the_payroll_pool(): void
    {
        $claim = $this->approvedClaim();

        $payment = app(ExpenseClaimBankPayment::class)->raise($claim);

        $this->assertSame(Payment::STATUS_DRAFT, $payment->status);
        $this->assertTrue($payment->payable->is($claim));
        $this->assertSame(5000.0, (float) $payment->amount);
        $this->assertSame($this->account->getKey(), $payment->company_bank_account_id);
        $this->assertStringContainsString('Fuel to the client site', $payment->details);

        // The file pays the employee, on the employee's bank details.
        $details = $payment->beneficiaryDetails();
        $this->assertSame('Nadeem Yahya', $details['name']);
        $this->assertSame('PK36MEZN0000001123456702', $details['account']);
        $this->assertTrue($payment->isReleasable());
        $this->assertFalse($payment->isEmployeeSalary(), 'a reimbursement is not salary');

        // Spoken for: still owed, no longer payroll's to pay.
        $claim->refresh();
        $this->assertSame($payment->getKey(), $claim->payment_id);
        $this->assertSame(ExpenseClaim::STATUS_APPROVED, $claim->status);
        $this->assertSame(0.0, app(ExpenseClaimService::class)->reimbursableFor($this->employee->id));
        $this->assertSame(1, ExpenseClaim::awaitingSettlement()->count(), 'the liability stands until the money moves');
    }

    public function test_approving_the_payment_clears_the_liability_and_settles_the_claim(): void
    {
        $claim = $this->approvedClaim();
        $this->assertSame(5000.0, $this->balance('2310'), 'approval booked the liability');

        $payment = app(ExpenseClaimBankPayment::class)->raise($claim);
        app(PaymentService::class)->approve($payment);

        $claim->refresh();
        $this->assertSame(ExpenseClaim::STATUS_SETTLED, $claim->status);
        $this->assertSame($payment->getKey(), $claim->payment_id);

        $entry = $payment->fresh()->journalEntry;
        $this->assertNotNull($entry);
        $this->assertTrue((bool) $entry->is_posted);
        $this->assertSame(5000.0, $this->debits($entry, '2310'));
        $this->assertSame(5000.0, $this->credits($entry, '1100'));

        $this->assertSame(0.0, $this->balance('2310'), 'the liability is cleared');
        $this->assertSame(5000.0, $this->debits(null, '5800'), 'the expense was booked once, at approval');

        // And payroll pays nothing for it.
        $payslip = Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'October',
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);
        $this->assertSame(0.0, (float) $payslip->fresh()->expense_reimbursement);
    }

    /** A claim approved before accrual began has no liability yet; paying it books the expense once. */
    public function test_a_claim_never_accrued_is_accrued_before_it_is_cleared(): void
    {
        $claim = ExpenseClaim::create([
            'employee_id' => $this->employee->id,
            'transaction_type_id' => TransactionType::byCode('fuel')->getKey(),
            'claimed_on' => '2026-08-02',
            'description' => 'Taxi, before the posting began',
            'amount' => 1200,
            'submitted_by' => $this->employee->user_id,
            'status' => ExpenseClaim::STATUS_APPROVED,
        ]);
        $this->assertNull($claim->journal_entry_id);

        $payment = app(ExpenseClaimBankPayment::class)->raise($claim);
        app(PaymentService::class)->approve($payment);

        $this->assertNotNull($claim->fresh()->journal_entry_id, 'accrued on the way');
        $this->assertSame(1200.0, $this->debits(null, '5800'));
        $this->assertSame(0.0, $this->balance('2310'));
        $this->assertSame(ExpenseClaim::STATUS_SETTLED, $claim->fresh()->status);
    }

    public function test_releasing_the_draft_into_a_file_settles_it_too(): void
    {
        $claim = $this->approvedClaim();
        $payment = app(ExpenseClaimBankPayment::class)->raise($claim);

        $released = app(PaymentService::class)->release([$payment], 'REIMB-2026-10-B1');

        $this->assertCount(1, $released);
        $this->assertSame(ExpenseClaim::STATUS_SETTLED, $claim->fresh()->status);
    }

    public function test_only_an_approved_claim_without_a_payment_can_be_raised(): void
    {
        $pending = ExpenseClaim::create([
            'employee_id' => $this->employee->id,
            'claimed_on' => '2026-10-02',
            'description' => 'Pending',
            'amount' => 100,
            'submitted_by' => $this->employee->user_id,
        ]);

        try {
            app(ExpenseClaimBankPayment::class)->raise($pending);
            $this->fail('A pending claim should have been refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('only an approved claim is paid', $e->getMessage());
        }

        $claim = $this->approvedClaim();
        app(ExpenseClaimBankPayment::class)->raise($claim);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already has bank payment');

        app(ExpenseClaimBankPayment::class)->raise($claim->fresh());
    }

    /** A draft discarded before it went returns the claim to payroll by itself. */
    public function test_deleting_the_draft_payment_returns_the_claim_to_payroll(): void
    {
        $claim = $this->approvedClaim();
        $payment = app(ExpenseClaimBankPayment::class)->raise($claim);

        $this->assertSame(0.0, app(ExpenseClaimService::class)->reimbursableFor($this->employee->id));

        $payment->delete();

        $this->assertNull($claim->fresh()->payment_id);
        $this->assertSame(5000.0, app(ExpenseClaimService::class)->reimbursableFor($this->employee->id));
    }

    public function test_the_claim_is_paid_from_its_row(): void
    {
        $claim = $this->approvedClaim();

        Livewire::test(ListExpenseClaims::class)
            ->callAction(TestAction::make('payThroughBank')->table($claim->getKey()), data: [
                'company_bank_account_id' => $this->account->getKey(),
                'value_date' => '2026-10-22',
            ]);

        $this->assertNotNull($claim->fresh()->payment_id);
        $this->assertSame(1, Payment::query()->where('payable_id', $claim->getKey())->count());
    }

    /** Two rows, because the lazy-load guard never arms on one (and the file reads the employee and their user). */
    public function test_two_claims_in_the_bank_file_resolve_their_employees(): void
    {
        app(ExpenseClaimBankPayment::class)->raise($this->approvedClaim(5000));
        app(ExpenseClaimBankPayment::class)->raise($this->approvedClaim(750));

        $rows = Livewire::test(BankPaymentFile::class)
            ->fillForm(['fiscal_year_id' => $this->fiscalYear->id, 'month' => 'October', 'type' => ExpenseClaimBankPayment::TRANSACTION_CODE])
            ->instance()
            ->getReleasableRows();

        $this->assertCount(2, $rows);
        $this->assertSame(['Nadeem Yahya', 'Nadeem Yahya'], $rows->map(fn (Payment $p) => $p->beneficiaryDetails()['name'])->all());
        $this->assertSame([5000.0, 750.0], $rows->map(fn (Payment $p) => (float) $p->amount)->all());
    }

    // ───────────────────────────────────────────────────── ledger helpers ──

    private function debits($entry, string $code): float
    {
        $query = JournalEntryLine::query()
            ->where('account_id', $this->accountId($code))
            ->whereHas('journalEntry', fn ($q) => $q->where('is_posted', true));

        if ($entry) {
            $query->where('journal_entry_id', $entry->getKey());
        }

        return round((float) $query->sum('debit_amount'), 2);
    }

    private function credits($entry, string $code): float
    {
        return round((float) $entry->lines()->where('account_id', $this->accountId($code))->sum('credit_amount'), 2);
    }

    private function balance(string $code): float
    {
        $lines = JournalEntryLine::query()
            ->where('account_id', $this->accountId($code))
            ->whereHas('journalEntry', fn ($q) => $q->where('is_posted', true));

        return round((float) (clone $lines)->sum('credit_amount') - (float) (clone $lines)->sum('debit_amount'), 2);
    }

    private function accountId(string $code): int
    {
        return Account::where('code', $code)->firstOrFail()->getKey();
    }
}
