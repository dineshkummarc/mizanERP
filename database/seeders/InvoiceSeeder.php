<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Inventory\Models\Product;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class InvoiceSeeder extends Seeder
{
    /**
     * Issued + paid sales invoices and supplier bills across elapsed
     * months, feeding the Phase 19 purchase lots and sales so A/R, A/P,
     * revenue, COGS, and inventory ledgers carry consistent demo data.
     * Idempotent via fixed invoice numbers.
     */
    public function run()
    {
        $fiscalYear = FiscalYear::where('is_active', true)->first();

        if (! $fiscalYear || ! Account::where('code', '1250')->exists() || ! Product::where('sku', 'LAP-DEV-01')->exists()) {
            $this->command?->warn('Needs an active fiscal year, account 1250, and inventory products; run earlier seeders first.');

            return;
        }

        $service = app(InvoiceService::class);
        $start = Carbon::parse($fiscalYear->start_date)->startOfMonth();
        $year = $start->format('Y');

        $supplier = Contact::where('name', ContactSeeder::SUPPLIER_HARDWARE)->first();
        $customer = Contact::where('name', ContactSeeder::CUSTOMER_PRIMARY)->first();
        $laptop = Product::where('sku', 'LAP-DEV-01')->first();
        $toner = Product::where('sku', 'TNR-HP-01')->first();

        $invoices = [
            // Supplier bill restocking laptops and toner (creates lots), fully paid.
            [
                'number' => "BILL-{$year}-900001",
                'kind' => 'purchase',
                'contact' => $supplier,
                'date' => $start->copy()->addDays(4),
                'pay' => 'full',
                'lines' => [
                    ['product' => $laptop, 'description' => 'Developer Laptop (Resale)', 'quantity' => 4, 'unit_price' => 210000],
                    ['product' => $toner, 'description' => 'HP Toner Cartridge', 'quantity' => 6, 'unit_price' => 29000],
                ],
            ],
            // Sales invoice with a product and a service line, half paid.
            [
                'number' => "INV-{$year}-900001",
                'kind' => 'sale',
                'contact' => $customer,
                'date' => $start->copy()->addDays(8),
                'pay' => 'half',
                'lines' => [
                    ['product' => $laptop, 'description' => 'Developer Laptop (Resale)', 'quantity' => 3, 'unit_price' => 275000],
                    ['product' => null, 'description' => 'On-site setup & configuration', 'quantity' => 1, 'unit_price' => 25000],
                ],
            ],
            // Open sales invoice (unpaid, drives the A/R aging buckets).
            [
                'number' => "INV-{$year}-900002",
                'kind' => 'sale',
                'contact' => Contact::where('name', ContactSeeder::CUSTOMER_SECONDARY)->first(),
                'date' => $start->copy()->addDays(12),
                'pay' => 'none',
                'lines' => [
                    ['product' => $toner, 'description' => 'HP Toner Cartridge', 'quantity' => 5, 'unit_price' => 38000],
                ],
            ],
        ];

        $created = 0;

        foreach ($invoices as $data) {
            if ($data['date']->greaterThan(now()) || Invoice::where('invoice_number', $data['number'])->exists()) {
                continue;
            }

            $subtotal = collect($data['lines'])->sum(fn ($l) => round($l['quantity'] * $l['unit_price'], 2));

            $invoice = Invoice::create([
                'invoice_number' => $data['number'],
                'kind' => $data['kind'],
                'contact_id' => $data['contact']->id,
                'invoice_date' => $data['date']->toDateString(),
                'due_date' => $data['date']->copy()->addDays(30)->toDateString(),
                'subtotal' => $subtotal,
                'tax_amount' => 0,
                'total' => $subtotal,
                'fiscal_year_id' => $fiscalYear->id,
            ]);

            foreach ($data['lines'] as $line) {
                $invoice->lines()->create([
                    'product_id' => $line['product']?->id,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => round($line['quantity'] * $line['unit_price'], 2),
                    'account_id' => $line['product'] ? null : Account::where('code', '4300')->value('id'),
                ]);
            }

            $service->issue($invoice);

            if ($data['pay'] === 'full') {
                $service->recordPayment($invoice, (float) $invoice->total, $data['date']->copy()->addDays(7)->toDateString());
            } elseif ($data['pay'] === 'half') {
                $service->recordPayment($invoice, round($invoice->total / 2, 2), $data['date']->copy()->addDays(10)->toDateString());
            }

            $created++;
        }

        $this->command?->info("Invoices: {$created} created and issued.");
    }
}
