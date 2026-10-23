<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Support\BankFileAccount;
use App\Modules\Core\Models\Bank;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\SalaryBankExportService;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The bank files are Standard Chartered iPayments. A beneficiary who banks with
 * SCB is an intra-bank transfer and must be keyed on the plain account number;
 * everybody else is an inter-bank IBFT and must be keyed on the IBAN.
 *
 * Sending the wrong identifier gets the payment rejected or misdirected, so both
 * the salary file and the general bank payment file are covered.
 */
class BankFileAccountSelectionTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private const SCB_IBAN = 'PK36SCBL0000001123456702';

    private const HBL_IBAN = 'PK24HABB0000001123456702';

    private function bank(string $short, string $name, string $code = '000001'): Bank
    {
        return Bank::firstOrCreate(
            ['bank_short_code' => $short],
            ['bank_name' => $name, 'bank_code' => $code],
        );
    }

    // --- the resolver itself -------------------------------------------------

    public function test_an_scb_short_code_selects_the_account_number(): void
    {
        $result = BankFileAccount::resolve(self::SCB_IBAN, '0102030405', $this->bank('SCB', 'Standard Chartered Bank'));

        $this->assertSame('0102030405', $result['value']);
        $this->assertSame('account_no', $result['kind']);
        $this->assertTrue($result['is_own_bank']);
    }

    public function test_another_bank_selects_the_iban(): void
    {
        $result = BankFileAccount::resolve(self::HBL_IBAN, '0102030405', $this->bank('HBL', 'Habib Bank Limited', '600648'));

        $this->assertSame(self::HBL_IBAN, $result['value']);
        $this->assertSame('iban', $result['kind']);
        $this->assertFalse($result['is_own_bank']);
    }

    /**
     * SCB is absent from the IBFT bank directory, so an SCB employee may have no
     * bank record at all — the IBAN's own bank identifier still gives it away.
     */
    public function test_an_scb_iban_is_recognised_without_any_bank_record(): void
    {
        $result = BankFileAccount::resolve(self::SCB_IBAN, '0102030405');

        $this->assertSame('0102030405', $result['value']);
        $this->assertTrue($result['is_own_bank']);
    }

    public function test_the_bank_name_alone_is_enough(): void
    {
        $result = BankFileAccount::resolve(null, '0102030405', null, null, 'Standard Chartered Bank (Pakistan) Ltd');

        $this->assertTrue($result['is_own_bank']);
        $this->assertSame('0102030405', $result['value']);
    }

    public function test_a_spaced_or_lowercase_iban_is_still_recognised(): void
    {
        $this->assertTrue(BankFileAccount::resolve('pk36 scbl 0000 0011 2345 6702', '1')['is_own_bank']);
    }

    /** A blank field in a payment file is worse than the other identifier. */
    public function test_it_falls_back_when_the_preferred_identifier_is_missing(): void
    {
        $scb = BankFileAccount::resolve(self::SCB_IBAN, null, $this->bank('SCB', 'Standard Chartered Bank'));
        $this->assertSame(self::SCB_IBAN, $scb['value'], 'no account number, so the IBAN is used');
        $this->assertSame('iban', $scb['kind']);

        $other = BankFileAccount::resolve(null, '0102030405', $this->bank('MCB', 'MCB Bank', '627100'));
        $this->assertSame('0102030405', $other['value'], 'no IBAN, so the account number is used');
        $this->assertSame('account_no', $other['kind']);
    }

    public function test_neither_on_file_yields_an_empty_value(): void
    {
        $result = BankFileAccount::resolve(null, null);

        $this->assertSame('', $result['value']);
        $this->assertSame('', $result['kind']);
    }

    // --- salary bank file ----------------------------------------------------

    private function employee(string $email, ?Bank $bank, ?string $iban, ?string $accountNo): Employee
    {
        return Employee::create([
            'user_id' => $this->makeUser('Employee', $email)->id,
            'employee_id' => 'EMP-'.strtoupper(substr(md5($email), 0, 5)),
            'gender' => 'Male',
            'phone' => '0300-0000000',
            'secondary_phone' => '0301-0000000',
            'bank_id' => $bank?->id,
            'iban_no' => $iban,
            'bank_account_no' => $accountNo,
        ]);
    }

    public function test_the_salary_file_uses_the_right_identifier_per_employee(): void
    {
        $scbEmployee = $this->employee('scb@test.local', $this->bank('SCB', 'Standard Chartered Bank'), self::SCB_IBAN, '0102030405');
        $hblEmployee = $this->employee('hbl@test.local', $this->bank('HBL', 'Habib Bank Limited', '600648'), self::HBL_IBAN, '0908070605');

        foreach ([$scbEmployee, $hblEmployee] as $employee) {
            Payslip::create([
                'employee_id' => $employee->id,
                'month' => 'January',
                'fiscal_year_id' => $this->fiscalYear->id,
                'net_salary' => 100000,
            ]);
        }

        $rows = collect(app(SalaryBankExportService::class)
            ->paymentsForMonth('January', $this->fiscalYear))
            ->keyBy('employee_code');

        $scbRow = $rows[$scbEmployee->employee_id];
        $hblRow = $rows[$hblEmployee->employee_id];

        $this->assertSame('0102030405', $scbRow['account'], 'SCB employee pays by account number');
        $this->assertSame('account_no', $scbRow['account_kind']);

        $this->assertSame(self::HBL_IBAN, $hblRow['account'], 'other banks pay by IBAN');
        $this->assertSame('iban', $hblRow['account_kind']);
    }

    // --- general bank payment file ------------------------------------------

    public function test_an_employee_payment_uses_the_right_identifier(): void
    {
        $employee = $this->employee('pay-scb@test.local', $this->bank('SCB', 'Standard Chartered Bank'), self::SCB_IBAN, '0102030405');

        $payment = new Payment;
        $payment->payable_type = Employee::class;
        $payment->payable_id = $employee->id;

        $this->assertSame('0102030405', $payment->beneficiaryDetails()['account']);
    }

    public function test_a_beneficiary_payment_uses_the_iban_for_other_banks(): void
    {
        $beneficiary = Beneficiary::create([
            'name' => 'Skyline Internet',
            'bank_id' => $this->bank('HBL', 'Habib Bank Limited', '600648')->id,
            'iban' => self::HBL_IBAN,
            'account_no' => '5544332211',
        ]);

        $payment = new Payment;
        $payment->payable_type = Beneficiary::class;
        $payment->payable_id = $beneficiary->id;

        $this->assertSame(self::HBL_IBAN, $payment->beneficiaryDetails()['account']);
    }

    public function test_a_beneficiary_at_scb_uses_the_account_number(): void
    {
        $beneficiary = Beneficiary::create([
            'name' => 'Office Landlord',
            'bank_id' => $this->bank('SCB', 'Standard Chartered Bank')->id,
            'iban' => self::SCB_IBAN,
            'account_no' => '5544332211',
        ]);

        $payment = new Payment;
        $payment->payable_type = Beneficiary::class;
        $payment->payable_id = $beneficiary->id;

        $this->assertSame('5544332211', $payment->beneficiaryDetails()['account']);
    }

    /** The rule is configurable rather than hardcoded to SCB. */
    public function test_the_own_bank_markers_come_from_config(): void
    {
        config(['ipayments.own_bank' => [
            'short_codes' => ['MCB'],
            'name_contains' => '',
            'iban_prefix' => '',
        ]]);

        $this->assertTrue(BankFileAccount::resolve(self::HBL_IBAN, '1', $this->bank('MCB', 'MCB Bank', '627100'))['is_own_bank']);
        $this->assertFalse(BankFileAccount::resolve(self::SCB_IBAN, '1', $this->bank('SCB', 'Standard Chartered Bank'))['is_own_bank']);
    }
}
