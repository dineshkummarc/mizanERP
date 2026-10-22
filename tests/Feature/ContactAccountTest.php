<?php

namespace Tests\Feature;

use App\Modules\Crm\Filament\RelationManagers\ContactNextActionsRelationManager;
use App\Modules\Crm\Filament\RelationManagers\ContactTimelineRelationManager;
use App\Modules\Inventory\Models\Product;
use App\Modules\Invoicing\Filament\Resources\Contacts\ContactResource;
use App\Modules\Invoicing\Filament\Resources\Contacts\Pages\EditContact;
use App\Modules\Invoicing\Filament\Resources\Contacts\Pages\ListContacts;
use App\Modules\Invoicing\Filament\Support\LinePricing;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Modules\Quotations\Filament\RelationManagers\ContactQuotationsRelationManager;
use App\Modules\Quotations\Models\Quotation;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The contact becomes an account — `docs/bexio-gap-plan.md` Phase 2, items 4, 5 and 8.
 *
 * The tabs are the part worth a test: every table they read existed, and the reason no screen read
 * them from the customer's side is that Invoicing may not name CRM or Quotations. The relation and
 * the tab are contributed from the other module's provider, and these assert the contribution lands.
 */
class ContactAccountTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'sales@test.local'));
        $this->setCurrentTenant();

        $this->customer = Contact::create([
            'name' => 'Karachi Textiles',
            'kind' => Contact::KIND_CUSTOMER,
            'category' => 'Textiles',
            'default_discount_pct' => 10,
        ]);
    }

    public function test_a_standing_discount_comes_off_a_catalogue_price(): void
    {
        $this->assertSame(900.0, $this->customer->discounted(1000));

        $plain = Contact::create(['name' => 'Lahore Looms', 'kind' => Contact::KIND_CUSTOMER]);
        $this->assertSame(1000.0, $plain->discounted(1000));
    }

    /** Choosing a product on a sale line fills the name, the discounted price and the total; a bill fills no price. */
    public function test_a_product_prices_the_line_for_this_customer(): void
    {
        $product = Product::create(['sku' => 'WH-1', 'name' => 'Warehouse licence', 'list_price' => 1000]);

        $line = ['description' => null, 'quantity' => 2, 'unit_price' => 0, 'line_total' => 0];
        // By reference on both sides, so the total is computed from the price just set.
        $get = function (string $key) use (&$line): mixed {
            return $line[$key] ?? null;
        };
        $set = function (string $key, mixed $value) use (&$line): void {
            $line[$key] = $value;
        };

        LinePricing::applyProduct($this->invoice(), $product->getKey(), $get, $set);

        $this->assertSame('Warehouse licence', $line['description']);
        $this->assertSame(900.0, $line['unit_price']);
        $this->assertSame(1800.0, $line['line_total']);

        $bill = ['description' => 'Stock', 'quantity' => 1, 'unit_price' => 0, 'line_total' => 0];
        $getBill = fn (string $key) => $bill[$key] ?? null;
        $setBill = function (string $key, mixed $value) use (&$bill): void {
            $bill[$key] = $value;
        };

        LinePricing::applyProduct($this->invoice(Invoice::KIND_PURCHASE), $product->getKey(), $getBill, $setBill);

        $this->assertSame('Stock', $bill['description']);
        $this->assertSame(0, $bill['unit_price']);
    }

    public function test_the_categories_offered_are_the_ones_in_use(): void
    {
        Contact::create(['name' => 'A', 'kind' => Contact::KIND_CUSTOMER, 'category' => 'Retail']);
        Contact::create(['name' => 'B', 'kind' => Contact::KIND_SUPPLIER]);

        $this->assertSame(['Retail', 'Textiles'], Contact::categories());
    }

    public function test_the_open_balance_is_what_they_owe_now(): void
    {
        $this->assertSame(0.0, $this->customer->openBalance());

        $invoice = $this->invoice();
        app(InvoiceService::class)->issue($invoice);
        app(InvoiceService::class)->recordPayment($invoice->refresh(), 40_000, '2026-09-15');

        $this->assertSame(60_000.0, $this->customer->openBalance());
    }

    /** Invoicing names neither module; both tabs still arrive, from their own providers. */
    public function test_the_contact_screen_gains_tabs_from_crm_and_quotations(): void
    {
        $relations = ContactResource::getRelations();

        $this->assertContains(ContactTimelineRelationManager::class, $relations);
        $this->assertContains(ContactNextActionsRelationManager::class, $relations);
        $this->assertContains(ContactQuotationsRelationManager::class, $relations);
    }

    public function test_the_timeline_tab_reads_calls_logged_against_the_customer(): void
    {
        $call = $this->customer->timeline()->create(['kind' => 'call', 'outcome' => 'Send pricing', 'duration_minutes' => 9]);

        Livewire::test(ContactTimelineRelationManager::class, [
            'ownerRecord' => $this->customer,
            'pageClass' => EditContact::class,
        ])->assertCanSeeTableRecords([$call]);
    }

    public function test_the_next_actions_tab_reads_follow_ups_for_the_customer(): void
    {
        $action = $this->customer->nextActions()->create(['title' => 'Call about the pricing', 'due_on' => now()->toDateString()]);

        Livewire::test(ContactNextActionsRelationManager::class, [
            'ownerRecord' => $this->customer,
            'pageClass' => EditContact::class,
        ])->assertCanSeeTableRecords([$action]);
    }

    public function test_the_quotes_tab_reads_what_was_quoted_to_the_customer(): void
    {
        $quote = Quotation::create(['contact_id' => $this->customer->getKey()]);

        Livewire::test(ContactQuotationsRelationManager::class, [
            'ownerRecord' => $this->customer,
            'pageClass' => EditContact::class,
        ])->assertCanSeeTableRecords([$quote]);
    }

    /** The list with its category and account-manager columns and filters renders. */
    public function test_the_contacts_list_renders_with_the_account_columns(): void
    {
        Livewire::test(ListContacts::class)
            ->assertCanSeeTableRecords([$this->customer])
            ->filterTable('category', 'Textiles')
            ->assertCanSeeTableRecords([$this->customer]);
    }

    private function invoice(string $kind = Invoice::KIND_SALE): Invoice
    {
        $invoice = Invoice::create([
            'kind' => $kind,
            'contact_id' => $this->customer->getKey(),
            'invoice_date' => '2026-09-01',
            'due_date' => '2026-10-01',
        ]);

        $invoice->lines()->create([
            'description' => 'Consultancy',
            'quantity' => 1,
            'unit_price' => 100_000,
            'line_total' => 100_000,
        ]);

        $invoice->forceFill(['subtotal' => 100_000, 'tax_amount' => 0, 'total' => 100_000])->save();

        return $invoice->refresh();
    }
}
