<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceService;
use InvalidArgumentException;
use Tests\AccountingTestCase;

class InvoiceTest extends AccountingTestCase
{
    private InvoiceService $service;

    private Contact $customer;

    private Contact $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InvoiceService::class);
        $this->customer = Contact::create(['name' => 'Test Customer', 'kind' => 'customer']);
        $this->supplier = Contact::create(['name' => 'Test Supplier', 'kind' => 'supplier']);

        $this->product = Product::create([
            'sku' => 'INV-TEST-01',
            'name' => 'Invoice Test Product',
            'unit' => 'pcs',
            'valuation_method' => 'fifo',
        ]);

        // Opening stock: 10 @ 100.
        app(InventoryService::class)->purchase($this->product, 10, 100, '2026-08-01');
    }

    private function makeInvoice(string $kind, array $lines, float $tax = 0): Invoice
    {
        $subtotal = collect($lines)->sum(fn ($l) => round($l['quantity'] * $l['unit_price'], 2));

        $invoice = Invoice::create([
            'kind' => $kind,
            'contact_id' => $kind === 'sale' ? $this->customer->id : $this->supplier->id,
            'invoice_date' => '2026-08-10',
            'due_date' => '2026-09-09',
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total' => round($subtotal + $tax, 2),
        ]);

        foreach ($lines as $line) {
            $invoice->lines()->create($line + ['line_total' => round($line['quantity'] * $line['unit_price'], 2)]);
        }

        return $invoice;
    }

    private function ledgerBalance(string $code): float
    {
        $account = Account::where('code', $code)->firstOrFail();

        $sums = JournalEntryLine::where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
            ->selectRaw('COALESCE(SUM(debit_amount),0) as d, COALESCE(SUM(credit_amount),0) as c')
            ->first();

        return round((float) $sums->d - (float) $sums->c, 2);
    }

    public function test_issuing_sale_invoice_posts_ar_revenue_tax_and_cogs(): void
    {
        $invoice = $this->makeInvoice('sale', [
            ['product_id' => $this->product->id, 'description' => 'Product', 'quantity' => 4, 'unit_price' => 250],
            ['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 500],
        ], tax: 150);

        $this->service->issue($invoice);
        $invoice->refresh();

        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->status);
        $this->assertStringStartsWith('INV-2026-', $invoice->invoice_number);
        $this->assertNotNull($invoice->journal_entry_id);

        $entry = $invoice->journalEntry;
        $this->assertSame((float) $entry->lines()->sum('debit_amount'), (float) $entry->lines()->sum('credit_amount'));

        $this->assertSame(1650.0, $this->ledgerBalance('1250'));   // A/R at total
        $this->assertSame(-1000.0, $this->ledgerBalance('4200'));  // product revenue
        $this->assertSame(-500.0, $this->ledgerBalance('4300'));   // service line
        $this->assertSame(-150.0, $this->ledgerBalance('2150'));   // sales tax
        $this->assertSame(400.0, $this->ledgerBalance('5050'));    // COGS 4 @ 100
    }

    public function test_issuing_purchase_bill_creates_lots_and_credits_ap(): void
    {
        $invoice = $this->makeInvoice('purchase', [
            ['product_id' => $this->product->id, 'description' => 'Restock', 'quantity' => 5, 'unit_price' => 120],
        ]);

        $this->service->issue($invoice);

        $this->assertStringStartsWith('BILL-2026-', $invoice->refresh()->invoice_number);
        $this->assertSame(-600.0, $this->ledgerBalance('2400'));

        $valuation = app(InventoryValuationService::class);
        $this->assertSame(15.0, $valuation->onHand($this->product));
        $this->assertSame(1600.0, $valuation->stockValue($this->product));

        $lot = $this->product->movements()->where('reference', $invoice->invoice_number)->first();
        $this->assertSame(5.0, (float) $lot->remaining_quantity);
    }

    public function test_sale_cogs_uses_purchase_lots_by_valuation_method(): void
    {
        // Add a second, pricier lot; FIFO should still consume the 100s first.
        app(InventoryService::class)->purchase($this->product, 10, 150, '2026-08-05');

        $invoice = $this->makeInvoice('sale', [
            ['product_id' => $this->product->id, 'description' => 'Product', 'quantity' => 12, 'unit_price' => 300],
        ]);

        $this->service->issue($invoice);

        $movement = $this->product->movements()->where('type', 'sale')->first();
        $this->assertSame(1300.0, (float) $movement->total_cost); // 10 @ 100 + 2 @ 150
    }

    public function test_payment_transitions_partially_paid_then_paid(): void
    {
        $invoice = $this->makeInvoice('sale', [
            ['product_id' => $this->product->id, 'description' => 'Product', 'quantity' => 2, 'unit_price' => 500],
        ]);
        $this->service->issue($invoice);

        $this->service->recordPayment($invoice, 400, '2026-08-15');
        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $invoice->refresh()->status);
        $this->assertSame(600.0, $invoice->outstanding());

        $this->service->recordPayment($invoice, 600, '2026-08-20');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->refresh()->status);
        $this->assertSame(0.0, $this->ledgerBalance('1250'));
    }

    public function test_overpayment_is_rejected(): void
    {
        $invoice = $this->makeInvoice('sale', [
            ['product_id' => $this->product->id, 'description' => 'Product', 'quantity' => 2, 'unit_price' => 500],
        ]);
        $this->service->issue($invoice);

        $this->expectException(InvalidArgumentException::class);
        $this->service->recordPayment($invoice, 1500, '2026-08-15');
    }

    public function test_void_reverses_ledger_and_restores_stock(): void
    {
        $invoice = $this->makeInvoice('sale', [
            ['product_id' => $this->product->id, 'description' => 'Product', 'quantity' => 4, 'unit_price' => 250],
        ]);
        $this->service->issue($invoice);

        $valuation = app(InventoryValuationService::class);
        $this->assertSame(6.0, $valuation->onHand($this->product));

        $this->service->void($invoice);

        $this->assertSame(Invoice::STATUS_VOID, $invoice->refresh()->status);
        $this->assertSame(0.0, $this->ledgerBalance('1250'));
        $this->assertSame(0.0, $this->ledgerBalance('4200'));
        $this->assertSame(0.0, $this->ledgerBalance('5050'));
        $this->assertSame(10.0, $valuation->onHand($this->product));
        $this->assertSame(1000.0, $valuation->stockValue($this->product));
    }

    public function test_void_blocked_once_paid(): void
    {
        $invoice = $this->makeInvoice('sale', [
            ['product_id' => $this->product->id, 'description' => 'Product', 'quantity' => 1, 'unit_price' => 500],
        ]);
        $this->service->issue($invoice);
        $this->service->recordPayment($invoice, 100, '2026-08-15');

        $this->expectException(InvalidArgumentException::class);
        $this->service->void($invoice->refresh());
    }

    public function test_mismatched_totals_are_rejected(): void
    {
        $invoice = Invoice::create([
            'kind' => 'sale',
            'contact_id' => $this->customer->id,
            'invoice_date' => '2026-08-10',
            'subtotal' => 999,
            'tax_amount' => 0,
            'total' => 999,
        ]);
        $invoice->lines()->create([
            'description' => 'Product',
            'quantity' => 2,
            'unit_price' => 500,
            'line_total' => 1000,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->issue($invoice);
    }

    public function test_non_product_purchase_line_requires_account(): void
    {
        $invoice = $this->makeInvoice('purchase', [
            ['description' => 'Cleaning services', 'quantity' => 1, 'unit_price' => 5000],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->issue($invoice);
    }

    public function test_aging_buckets_group_by_days_overdue(): void
    {
        $old = $this->makeInvoice('sale', [
            ['product_id' => $this->product->id, 'description' => 'Product', 'quantity' => 2, 'unit_price' => 500],
        ]);
        $old->update(['invoice_date' => '2026-08-10', 'due_date' => '2026-08-10']);
        $this->service->issue($old);

        $recent = $this->makeInvoice('sale', [
            ['product_id' => $this->product->id, 'description' => 'Product', 'quantity' => 1, 'unit_price' => 500],
        ]);
        $recent->update(['due_date' => '2026-10-10']);
        $this->service->issue($recent);

        $aging = $this->service->outstandingReceivables('2026-10-24');

        $this->assertSame(1000.0, $aging['buckets']['61-90']); // due 2026-08-10, 75 days over
        $this->assertSame(500.0, $aging['buckets']['current']); // due 2026-10-10, 14 days over
        $this->assertSame(1500.0, $aging['total']);
    }
}
