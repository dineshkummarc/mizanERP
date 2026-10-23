<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\CompanyBankAccount;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Services\BankPaymentExportService;
use App\Modules\Core\Models\Bank;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\SalaryBankExportService;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * A salary transfer carries its own iPayments payment type — PAY — in both the
 * Salary Bank File (every row is payroll) and the Bank Payment File (only the
 * rows that settle payroll).
 *
 * It outranks the other routing rules: payroll goes out as payroll even when the
 * amount is above the RTGS threshold or the employee banks with SCB.
 */
class SalaryPaymentTypeTest extends AccountingTestCase
{
    use InteractsWithTenant;

    /** Payment Type is column 2 of the template, so index 1 once parsed. */
    private const PAYMENT_TYPE_COLUMN = 1;

    private function employee(string $email): Employee
    {
        return Employee::create([
            'user_id' => $this->makeUser('Employee', $email)->id,
            'employee_id' => 'EMP-'.strtoupper(substr(md5($email), 0, 5)),
            'gender' => 'Male',
            'phone' => '0300-0000000',
            'secondary_phone' => '0301-0000000',
            'bank_account_no' => '0102030405',
            'iban_no' => 'PK24HABB0000001123456702',
        ]);
    }

    private function settingFor(Employee $employee, float $basicWage): void
    {
        EmployeeSetting::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => $basicWage,
        ]);
    }

    private function type(string $code): TransactionType
    {
        return TransactionType::firstOrCreate(
            ['code' => $code],
            ['name' => ucfirst($code)],
        );
    }

    // --- Salary Bank File ----------------------------------------------------

    public function test_every_salary_file_row_uses_pay(): void
    {
        $employee = $this->employee('salary-file@test.local');

        Payslip::create([
            'employee_id' => $employee->id,
            'month' => 'January',
            'fiscal_year_id' => $this->fiscalYear->id,
            'net_salary' => 100000,
        ]);

        $csv = app(SalaryBankExportService::class)->export('January', $this->fiscalYear);

        $rows = array_values(array_filter(
            array_map(fn ($line) => str_getcsv($line), explode("\n", trim($csv))),
            fn ($cols) => ($cols[0] ?? '') === 'P',
        ));

        $this->assertNotEmpty($rows, 'the file should contain a payment row');

        foreach ($rows as $cols) {
            $this->assertSame('PAY', $cols[self::PAYMENT_TYPE_COLUMN]);
        }
    }

    /**
     * The salary file applies the same threshold rule, row by row.
     *
     * `net_salary` is recomputed from the employee's salary settings on save, so
     * the amount has to come from a real EmployeeSetting rather than being set
     * on the payslip directly.
     */
    public function test_a_high_value_salary_file_row_is_rtgs(): void
    {
        $small = $this->employee('small-salary@test.local');
        $large = $this->employee('large-salary@test.local');

        $this->settingFor($small, 100000);
        $this->settingFor($large, 2000000);

        foreach ([$small, $large] as $employee) {
            Payslip::create([
                'employee_id' => $employee->id,
                'month' => 'April',
                'fiscal_year_id' => $this->fiscalYear->id,
            ]);
        }

        $payments = collect(app(SalaryBankExportService::class)
            ->paymentsForMonth('April', $this->fiscalYear))
            ->keyBy('employee_code');

        // Guard the premise: one row must actually straddle the threshold.
        $this->assertGreaterThanOrEqual(
            Payment::RTGS_THRESHOLD,
            $payments[$large->employee_id]['amount'],
            'the large payslip must exceed the RTGS threshold for this test to mean anything'
        );
        $this->assertLessThan(
            Payment::RTGS_THRESHOLD,
            $payments[$small->employee_id]['amount'],
        );

        $types = collect(explode("\n", trim(app(SalaryBankExportService::class)->export('April', $this->fiscalYear))))
            ->map(fn ($line) => str_getcsv($line))
            ->filter(fn ($cols) => ($cols[0] ?? '') === 'P')
            ->map(fn ($cols) => $cols[self::PAYMENT_TYPE_COLUMN])
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(['PAY', 'RTGS'], $types, 'the large row must route RTGS');
    }

    public function test_the_salary_type_is_configurable(): void
    {
        config(['ipayments.salary_payment_type' => 'PAYROLL']);

        $employee = $this->employee('salary-config@test.local');

        Payslip::create([
            'employee_id' => $employee->id,
            'month' => 'February',
            'fiscal_year_id' => $this->fiscalYear->id,
            'net_salary' => 50000,
        ]);

        $csv = app(SalaryBankExportService::class)->export('February', $this->fiscalYear);

        $this->assertStringContainsString('PAYROLL', $csv);
    }

    // --- Bank Payment File ---------------------------------------------------

    private function payment(array $attributes): Payment
    {
        return Payment::create($attributes + [
            'amount' => 100000,
            'details' => 'Test payment',
            'status' => Payment::STATUS_APPROVED,
            // The column is NOT NULL; 'fuel' is deliberately not the salary type
            // so the payslip-linked cases prove payslip_id alone is decisive.
            'transaction_type_id' => $this->type('fuel')->id,
        ]);
    }

    public function test_a_payment_settling_a_payslip_is_pay(): void
    {
        $employee = $this->employee('payslip-payment@test.local');

        $payslip = Payslip::create([
            'employee_id' => $employee->id,
            'month' => 'January',
            'fiscal_year_id' => $this->fiscalYear->id,
            'net_salary' => 100000,
        ]);

        $payment = $this->payment([
            'payable_type' => Employee::class,
            'payable_id' => $employee->id,
            'payslip_id' => $payslip->id,
        ]);

        $this->assertTrue($payment->isEmployeeSalary());
        $this->assertSame('PAY', $payment->resolvedPaymentType());
    }

    public function test_an_employee_payment_under_the_salary_type_is_pay(): void
    {
        $employee = $this->employee('salary-type@test.local');

        $payment = $this->payment([
            'payable_type' => Employee::class,
            'payable_id' => $employee->id,
            'transaction_type_id' => $this->type('salary')->id,
        ]);

        $this->assertSame('PAY', $payment->resolvedPaymentType());
    }

    /**
     * An employee can also be paid an advance or a reimbursement. Those are
     * ordinary transfers, so "the payee is an employee" must not be enough.
     */
    public function test_a_non_salary_payment_to_an_employee_is_not_pay(): void
    {
        $employee = $this->employee('advance@test.local');

        $payment = $this->payment([
            'payable_type' => Employee::class,
            'payable_id' => $employee->id,
            'transaction_type_id' => $this->type('fuel')->id,
            'amount' => 5000,
        ]);

        $this->assertFalse($payment->isEmployeeSalary());
        $this->assertSame('IBFT', $payment->resolvedPaymentType());
    }

    public function test_a_beneficiary_payment_is_unaffected(): void
    {
        $beneficiary = Beneficiary::create([
            'name' => 'Skyline Internet',
            'account_no' => '5544332211',
        ]);

        $payment = $this->payment([
            'payable_type' => Beneficiary::class,
            'payable_id' => $beneficiary->id,
            'transaction_type_id' => $this->type('utilities')->id,
            'amount' => 20000,
        ]);

        $this->assertSame('IBFT', $payment->resolvedPaymentType());
    }

    /** The bank requires RTGS above the threshold, whatever the payment is for. */
    public function test_a_salary_above_the_rtgs_threshold_is_rtgs_not_pay(): void
    {
        $employee = $this->employee('big-salary@test.local');

        $payment = $this->payment([
            'payable_type' => Employee::class,
            'payable_id' => $employee->id,
            'transaction_type_id' => $this->type('salary')->id,
            'amount' => Payment::RTGS_THRESHOLD + 1,
        ]);

        $this->assertSame('RTGS', $payment->resolvedPaymentType());
    }

    /** Exactly at the threshold counts as above it. */
    public function test_a_salary_exactly_at_the_threshold_is_rtgs(): void
    {
        $employee = $this->employee('threshold-salary@test.local');

        $payment = $this->payment([
            'payable_type' => Employee::class,
            'payable_id' => $employee->id,
            'transaction_type_id' => $this->type('salary')->id,
            'amount' => Payment::RTGS_THRESHOLD,
        ]);

        $this->assertSame('RTGS', $payment->resolvedPaymentType());
    }

    /** Just below it, payroll is still payroll. */
    public function test_a_salary_below_the_threshold_is_pay(): void
    {
        $employee = $this->employee('under-threshold@test.local');

        $payment = $this->payment([
            'payable_type' => Employee::class,
            'payable_id' => $employee->id,
            'transaction_type_id' => $this->type('salary')->id,
            'amount' => Payment::RTGS_THRESHOLD - 1,
        ]);

        $this->assertSame('PAY', $payment->resolvedPaymentType());
    }

    /** …and outranks the intra-bank BT rule. */
    public function test_a_salary_to_the_debiting_bank_is_still_pay_not_bt(): void
    {
        $bank = Bank::firstOrCreate(['bank_short_code' => 'SCB'], ['bank_name' => 'Standard Chartered Bank', 'bank_code' => '000001']);

        $employee = $this->employee('same-bank@test.local');
        $employee->forceFill(['bank_id' => $bank->id])->save();

        $debitAccount = CompanyBankAccount::create([
            'account_no' => '1234567801',
            'title' => 'Main Salary Account',
            'bank_id' => $bank->id,
        ]);

        $payment = $this->payment([
            'payable_type' => Employee::class,
            'payable_id' => $employee->id,
            'transaction_type_id' => $this->type('salary')->id,
            'company_bank_account_id' => $debitAccount->id,
        ]);

        $this->assertSame('PAY', $payment->resolvedPaymentType());
    }

    /** An explicit per-payment override still wins, for the odd exception. */
    public function test_an_explicit_override_still_wins(): void
    {
        $employee = $this->employee('override@test.local');

        $payment = $this->payment([
            'payable_type' => Employee::class,
            'payable_id' => $employee->id,
            'transaction_type_id' => $this->type('salary')->id,
            'payment_type' => 'RTGS',
        ]);

        $this->assertSame('RTGS', $payment->resolvedPaymentType());
    }

    /** End to end: the emitted Bank Payment File row carries PAY. */
    public function test_the_bank_payment_file_row_carries_pay(): void
    {
        $employee = $this->employee('bpf@test.local');

        $payslip = Payslip::create([
            'employee_id' => $employee->id,
            'month' => 'March',
            'fiscal_year_id' => $this->fiscalYear->id,
            'net_salary' => 75000,
        ]);

        $payment = $this->payment([
            'payable_type' => Employee::class,
            'payable_id' => $employee->id,
            'payslip_id' => $payslip->id,
            'amount' => 75000,
        ]);

        $csv = app(BankPaymentExportService::class)->exportPayments(collect([$payment]));

        $paymentRow = collect(explode("\n", trim($csv)))
            ->map(fn ($line) => str_getcsv($line))
            ->firstWhere(fn ($cols) => ($cols[0] ?? '') === 'P');

        $this->assertSame('PAY', $paymentRow[self::PAYMENT_TYPE_COLUMN]);
    }
}
