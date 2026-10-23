<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Invoicing\Filament\Pages\AgedPayables;
use App\Modules\Invoicing\Filament\Pages\AgedReceivables;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceService;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Aged receivables and payables.
 *
 * The buckets were computed all along — `outstandingReceivables()` and
 * `outstandingPayables()` have been in InvoiceService since it was written — and
 * the only caller anywhere was a test. No page, route, widget or endpoint reached
 * either, so the answer to "who owes us, and how late are they?" existed and was
 * unreadable.
 */
class AgedInvoicesTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Contact $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'aged@test.local'));
        $this->setCurrentTenant();

        $this->client = Contact::create([
            'name' => 'Erbium AG',
            'kind' => Contact::KIND_BOTH,
            'is_active' => true,
        ]);
    }

    /**
     * Issued through the service, so the invoice is posted and `outstanding()` is
     * real rather than a status set by hand.
     */
    private function issue(string $kind, float $amount, string $dueDate): Invoice
    {
        $invoice = Invoice::create([
            'kind' => $kind,
            'contact_id' => $this->client->id,
            'invoice_date' => '2026-01-01',
            'due_date' => $dueDate,
            'fiscal_year_id' => $this->fiscalYear->id,
            'subtotal' => $amount,
            'tax_amount' => 0,
            'total' => $amount,
        ]);

        $invoice->lines()->create([
            'description' => 'Services',
            'quantity' => 1,
            'unit_price' => $amount,
            'line_total' => $amount,
            'account_id' => Account::where('code', $kind === Invoice::KIND_SALE ? '4100' : '5700')->firstOrFail()->id,
        ]);

        return app(InvoiceService::class)->issue($invoice->refresh());
    }

    public function test_an_invoice_95_days_overdue_lands_in_the_oldest_bucket(): void
    {
        $this->issue(Invoice::KIND_SALE, 92000, '2026-05-01');

        $report = app(InvoiceService::class)->outstandingReceivables('2026-08-04');

        $this->assertSame(92000.0, $report['buckets']['90+']);
        $this->assertSame(0.0, $report['buckets']['current']);
        $this->assertSame(95, $report['invoices'][0]['days_overdue']);
    }

    public function test_each_bucket_takes_its_own(): void
    {
        $asOf = '2026-08-04';

        $this->issue(Invoice::KIND_SALE, 1000, '2026-08-01');   // 3 days
        $this->issue(Invoice::KIND_SALE, 2000, '2026-06-20');   // 45 days
        $this->issue(Invoice::KIND_SALE, 3000, '2026-05-20');   // 76 days
        $this->issue(Invoice::KIND_SALE, 4000, '2026-01-20');   // 196 days

        $buckets = app(InvoiceService::class)->outstandingReceivables($asOf)['buckets'];

        $this->assertSame(1000.0, $buckets['current']);
        $this->assertSame(2000.0, $buckets['31-60']);
        $this->assertSame(3000.0, $buckets['61-90']);
        $this->assertSame(4000.0, $buckets['90+']);
    }

    public function test_a_part_paid_invoice_ages_by_what_is_left(): void
    {
        $invoice = $this->issue(Invoice::KIND_SALE, 100000, '2026-05-01');

        app(InvoiceService::class)->recordPayment($invoice, 40000, '2026-06-01');

        $report = app(InvoiceService::class)->outstandingReceivables('2026-08-04');

        $this->assertSame(60000.0, $report['buckets']['90+'], 'the unpaid remainder, not the invoice total');
    }

    public function test_a_paid_invoice_drops_off(): void
    {
        $invoice = $this->issue(Invoice::KIND_SALE, 100000, '2026-05-01');

        app(InvoiceService::class)->recordPayment($invoice, 100000, '2026-06-01');

        $this->assertSame(0.0, app(InvoiceService::class)->outstandingReceivables('2026-08-04')['total']);
    }

    public function test_receivables_and_payables_do_not_mix(): void
    {
        // The same contact is both a customer and a supplier here, which is exactly
        // when the two reports must not borrow from each other.
        $this->issue(Invoice::KIND_SALE, 92000, '2026-05-01');
        $this->issue(Invoice::KIND_PURCHASE, 15000, '2026-05-01');

        $invoices = app(InvoiceService::class);

        $this->assertSame(92000.0, $invoices->outstandingReceivables('2026-08-04')['total']);
        $this->assertSame(15000.0, $invoices->outstandingPayables('2026-08-04')['total']);
    }

    public function test_the_receivables_page_shows_the_oldest_first(): void
    {
        $this->issue(Invoice::KIND_SALE, 1000, '2026-08-01');
        $this->issue(Invoice::KIND_SALE, 4000, '2026-01-20');

        $page = Livewire::test(AgedReceivables::class);

        $page->assertSee('Aged Receivables')->assertSee('4,000.00')->assertSee('1,000.00');

        $rows = $page->instance()->rows();

        $this->assertSame(4000.0, $rows[0]['outstanding'], 'the most overdue row is first');
    }

    public function test_the_payables_page_reads_the_other_direction(): void
    {
        $this->issue(Invoice::KIND_PURCHASE, 15000, '2026-05-01');
        $this->issue(Invoice::KIND_SALE, 92000, '2026-05-01');

        Livewire::test(AgedPayables::class)
            ->assertSee('Aged Payables')
            ->assertSee('15,000.00')
            ->assertDontSee('92,000.00');
    }

    public function test_both_report_pages_and_their_pdfs_are_reachable(): void
    {
        $this->issue(Invoice::KIND_SALE, 92000, '2026-05-01');
        $this->issue(Invoice::KIND_PURCHASE, 15000, '2026-05-01');

        foreach (['reports.aged-receivables' => '92,000.00', 'reports.aged-payables' => '15,000.00'] as $route => $expected) {
            $url = route($route, ['company' => $this->tenant->slug, 'as_of' => '2026-08-04']);

            $this->get($url)->assertOk()->assertSee($expected)->assertSee('90+ days');
            $this->get($url.'&format=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        }
    }

    public function test_an_empty_book_says_so_rather_than_showing_an_empty_table(): void
    {
        Livewire::test(AgedReceivables::class)->assertSee('Nothing outstanding');
    }
}
