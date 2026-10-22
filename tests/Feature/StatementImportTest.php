<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankStatement;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\CompanyBankAccount;
use App\Modules\Accounting\Support\StatementCsv;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Modules\Invoicing\Services\StatementReceipts;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The bank's own CSV, and a receipt recorded from the line that is it — `docs/bexio-gap-plan.md`
 * Phase 3, item 12.
 */
class StatementImportTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Account $cash;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'bank@test.local'));
        $this->setCurrentTenant();

        $this->cash = Account::where('code', '1100')->firstOrFail();
        $this->customer = Contact::create(['name' => 'Karachi Textiles', 'kind' => Contact::KIND_CUSTOMER]);
    }

    // ───────────────────────────────────────────────── reading the file ──

    public function test_a_banks_csv_is_read_through_its_column_mapping(): void
    {
        $csv = "Txn Date,Particulars,Ref No,Debit,Credit\n"
            ."22/10/2026,KARACHI TEXTILES IBFT,INV-2026-0001,,\"100,000.00\"\n"
            ."23/10/2026,Bank charges,,250.00,\n";

        $rows = StatementCsv::rows($csv, [
            'date' => 'Txn Date',
            'date_format' => 'd/m/Y',
            'description' => 'Particulars',
            'reference' => 'Ref No',
            'credit' => 'Credit',
            'debit' => 'Debit',
        ]);

        $this->assertCount(2, $rows);
        $this->assertSame(['transaction_date' => '2026-10-22', 'description' => 'KARACHI TEXTILES IBFT', 'reference' => 'INV-2026-0001', 'amount' => 100_000.0], $rows[0]);
        $this->assertSame('2026-10-23', $rows[1]['transaction_date']);
        $this->assertSame(-250.0, $rows[1]['amount']);
    }

    /** What banks actually print in an amount column. */
    public function test_a_signed_amount_column_reads_brackets_signs_and_suffixes(): void
    {
        $csv = "Date,Narration,Amount\n2026-10-22,a,\"(1,500.00)\"\n2026-10-23,b,-200\n2026-10-24,c,300 CR\n2026-10-25,d,75 DR\n";

        $amounts = array_column(StatementCsv::rows($csv, ['date' => 'Date', 'description' => 'Narration', 'amount' => 'Amount']), 'amount');

        $this->assertSame([-1500.0, -200.0, 300.0, -75.0], $amounts);
    }

    public function test_a_column_that_is_not_in_the_file_is_named(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No column called "Date" in the file. The header row has: txn date, amount');

        StatementCsv::rows("Txn Date,Amount\n2026-10-22,5\n", ['date' => 'Date', 'amount' => 'Amount']);
    }

    public function test_an_import_needs_an_amount_column_of_some_kind(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Say which column holds the amount');

        StatementCsv::rows("Date,Narration\n2026-10-22,x\n", ['date' => 'Date', 'description' => 'Narration']);
    }

    public function test_the_mapping_is_remembered_on_the_bank_account(): void
    {
        $account = CompanyBankAccount::create(['title' => 'Main', 'account_no' => '0101', 'is_active' => true]);

        $account->update(['statement_mapping' => ['date' => 'Txn Date', 'date_format' => 'd/m/Y', 'credit' => 'Credit', 'debit' => 'Debit']]);

        $this->assertSame('Txn Date', $account->fresh()->statement_mapping['date']);
        $this->assertSame('d/m/Y', $account->fresh()->statement_mapping['date_format']);
    }

    // ───────────────────────────────────────── a receipt from the line ──

    public function test_a_receipt_is_recorded_from_the_statement_line_and_the_line_matched(): void
    {
        $invoice = $this->issuedInvoice(100_000);
        $line = $this->line(100_000, 'KARACHI TEXTILES IBFT');
        $receipts = app(StatementReceipts::class);

        $this->assertTrue($receipts->suggest($line)?->is($invoice));

        $receipts->record($line, $invoice);

        $line->refresh();
        $this->assertSame(BankStatementLine::STATUS_MANUALLY_MATCHED, $line->match_status);
        $this->assertSame($this->cash->getKey(), $line->matchedLine->account_id);
        $this->assertSame(100_000.0, (float) $line->matchedLine->debit_amount);
        $this->assertNotNull($line->matchedLine->reconciled_at);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /** The invoice's own guard holds, and the transaction leaves the line untouched. */
    public function test_a_line_that_pays_more_than_the_invoice_is_owed_is_refused(): void
    {
        $invoice = $this->issuedInvoice(100_000);
        $line = $this->line(150_000, 'KARACHI TEXTILES');

        try {
            app(StatementReceipts::class)->record($line, $invoice);
            $this->fail('A receipt beyond the balance should have been refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('exceeds outstanding balance', $e->getMessage());
        }

        $this->assertSame(BankStatementLine::STATUS_UNMATCHED, $line->fresh()->match_status);
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);
    }

    public function test_the_suggestion_reads_the_invoice_number_when_amounts_tie(): void
    {
        $first = $this->issuedInvoice(50_000);
        $second = $this->issuedInvoice(50_000);

        $this->assertNull(app(StatementReceipts::class)->suggest($this->line(50_000, 'IBFT received')));
        $this->assertTrue(app(StatementReceipts::class)->suggest($this->line(50_000, "Payment {$second->invoice_number}"))?->is($second));
        $this->assertFalse($first->is($second));
    }

    private function issuedInvoice(float $amount): Invoice
    {
        $invoice = Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $this->customer->getKey(),
            'invoice_date' => '2026-10-01',
            'due_date' => '2026-10-31',
        ]);

        $invoice->lines()->create(['description' => 'Consultancy', 'quantity' => 1, 'unit_price' => $amount, 'line_total' => $amount]);
        $invoice->forceFill(['subtotal' => $amount, 'tax_amount' => 0, 'total' => $amount])->save();

        app(InvoiceService::class)->issue($invoice);

        return $invoice->refresh();
    }

    private function line(float $amount, string $description): BankStatementLine
    {
        $statement = BankStatement::create([
            'account_id' => $this->cash->getKey(),
            'statement_date' => '2026-10-31',
            'opening_balance' => 0,
            'closing_balance' => $amount,
        ]);

        return $statement->lines()->create([
            'transaction_date' => '2026-10-22',
            'description' => $description,
            'amount' => $amount,
        ]);
    }
}
