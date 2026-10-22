<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\CompanyBankAccount;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Services\PaymentService;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceEvent;
use App\Modules\Invoicing\Services\BillPaymentService;
use App\Modules\Invoicing\Services\InvoiceService;
use Database\Seeders\TransactionTypeSeeder;
use Filament\Actions\Testing\TestAction;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * A supplier bill reaching the bank file — `docs/bexio-gap-plan.md` Phase 3, item 11.
 *
 * The two that carry it: the bill is settled when the payment is approved and not before (a draft in
 * the file has moved no money), and a second payment cannot be raised for money a draft already covers.
 */
class BillBankPaymentTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Contact $supplier;

    private CompanyBankAccount $account;

    private BillPaymentService $bills;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'payables@test.local'));
        $this->setCurrentTenant();

        $this->seed(TransactionTypeSeeder::class);

        // A Meezan IBAN, not a Standard Chartered one: the file is sent from SCB, and an own-bank payee
        // with only an IBAN is correctly held back (BankFileAccount::PROBLEM_OWN_BANK_IBAN_ONLY).
        $this->supplier = Contact::create([
            'name' => 'Lahore Looms',
            'kind' => Contact::KIND_SUPPLIER,
            'iban' => 'PK36MEZN0000001123456702',
        ]);

        $this->account = CompanyBankAccount::create([
            'title' => 'Main current account',
            'account_no' => '0101-2345',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->bills = app(BillPaymentService::class);
    }

    public function test_raising_a_payment_puts_the_bill_in_the_next_bank_file_without_settling_it(): void
    {
        $bill = $this->bill();

        $payment = $this->bills->raise($bill);

        $this->assertSame(Payment::STATUS_DRAFT, $payment->status);
        $this->assertTrue($payment->payable->is($bill));
        $this->assertSame(100_000.0, (float) $payment->amount);
        $this->assertSame($this->account->getKey(), $payment->company_bank_account_id);
        $this->assertStringContainsString('Lahore Looms', $payment->details);
        $this->assertTrue(Payment::unreleased()->whereKey($payment->getKey())->exists());

        // The file pays the supplier on the bill, on the details from the contact record.
        $details = $payment->beneficiaryDetails();
        $this->assertSame('Lahore Looms', $details['name']);
        $this->assertSame('PK36MEZN0000001123456702', $details['account']);
        $this->assertSame('IBFT', $payment->resolvedPaymentType());

        // Nothing has moved yet.
        $this->assertSame(100_000.0, $bill->fresh()->outstanding());
        $this->assertSame(1, $bill->events()->where('event', InvoiceEvent::PAYMENT_RAISED)->count());
    }

    public function test_approving_the_payment_settles_the_bill_through_its_own_path(): void
    {
        $bill = $this->bill();
        $payment = $this->bills->raise($bill);

        app(PaymentService::class)->approve($payment);

        $bill->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $bill->status);
        $this->assertSame(100_000.0, (float) $bill->amount_paid);
        $this->assertSame(1, $bill->events()->where('event', InvoiceEvent::PAYMENT)->count());

        // Accounts Payable relieved, the bank credited — and the expense not booked a second time.
        $entry = $payment->fresh()->journalEntry;
        $this->assertNotNull($entry);
        $this->assertTrue((bool) $entry->is_posted);
        $this->assertSame(100_000.0, (float) $entry->lines()->where('account_id', $this->accountId('2400'))->sum('debit_amount'));
        $this->assertSame(100_000.0, (float) $entry->lines()->where('account_id', $this->accountId('1100'))->sum('credit_amount'));
        $this->assertSame(0.0, (float) $entry->lines()->where('account_id', $this->accountId('5700'))->sum('debit_amount'));
    }

    public function test_releasing_a_draft_into_a_file_settles_it_too(): void
    {
        $bill = $this->bill();
        $payment = $this->bills->raise($bill);

        $released = app(PaymentService::class)->release([$payment], 'VEN-2026-10-B1');

        $this->assertCount(1, $released);
        $this->assertSame(Payment::STATUS_EXPORTED, $payment->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $bill->fresh()->status);
    }

    /** A draft already waiting in the file counts against what may be raised again. */
    public function test_what_is_left_to_pay_counts_drafts_already_raised(): void
    {
        $bill = $this->bill();

        $this->bills->raise($bill, 40_000);
        $this->assertSame(60_000.0, $this->bills->leftToPay($bill));

        try {
            $this->bills->raise($bill, 70_000);
            $this->fail('More than is left should have been refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('60,000.00 left to pay', $e->getMessage());
        }

        $this->bills->raise($bill, 60_000);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Nothing is left to pay');

        $this->bills->raise($bill);
    }

    public function test_a_customer_invoice_is_not_paid_through_the_bank(): void
    {
        $customer = Contact::create(['name' => 'Karachi Textiles', 'kind' => Contact::KIND_CUSTOMER]);
        $invoice = $this->bill(Invoice::KIND_SALE, $customer);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('settled by a receipt');

        $this->bills->raise($invoice);
    }

    public function test_the_payment_names_the_bill_and_the_supplier(): void
    {
        $bill = $this->bill();

        $this->assertSame("Bill {$bill->invoice_number}", $bill->paymentLabel());
        $this->assertTrue($bill->payee()->is($this->supplier));
    }

    public function test_the_bill_is_paid_from_its_row(): void
    {
        $bill = $this->bill();

        Livewire::test(ListInvoices::class)
            ->callAction(TestAction::make('payThroughBank')->table($bill->getKey()), data: [
                'amount' => 100_000,
                'company_bank_account_id' => $this->account->getKey(),
                'value_date' => '2026-10-22',
            ]);

        $this->assertSame(1, Payment::query()->where('payable_id', $bill->getKey())->count());
    }

    private function bill(string $kind = Invoice::KIND_PURCHASE, ?Contact $contact = null): Invoice
    {
        $invoice = Invoice::create([
            'kind' => $kind,
            'contact_id' => ($contact ?? $this->supplier)->getKey(),
            'invoice_date' => '2026-10-01',
            'due_date' => '2026-10-31',
        ]);

        $invoice->lines()->create([
            'description' => $kind === Invoice::KIND_PURCHASE ? 'Office rent, October' : 'Consultancy',
            'quantity' => 1,
            'unit_price' => 100_000,
            'line_total' => 100_000,
            'account_id' => $kind === Invoice::KIND_PURCHASE ? $this->accountId('5700') : null,
        ]);

        $invoice->forceFill(['subtotal' => 100_000, 'tax_amount' => 0, 'total' => 100_000])->save();

        app(InvoiceService::class)->issue($invoice);

        return $invoice->refresh();
    }

    private function accountId(string $code): int
    {
        return Account::where('code', $code)->firstOrFail()->getKey();
    }
}
