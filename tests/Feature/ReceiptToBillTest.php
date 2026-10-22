<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\LocalPatternModel;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\ReceiptReader;
use App\Support\Ai\FakeStructuredModel;
use App\Support\Ai\ModelUnavailable;
use App\Support\Ai\StructuredModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * A photographed receipt into a draft bill — `docs/bexio-gap-plan.md` Phase 4, item 14.
 *
 * The model is faked: reading pixels is the one non-deterministic step, and everything after it — the
 * supplier lookup, the arithmetic, the draft and its attachment — is asserted exactly. The two that
 * carry the file: the result is a draft (nothing posts), and the supplier is found before it is created.
 */
class ReceiptToBillTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private FakeStructuredModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'receipts@test.local'));
        $this->setCurrentTenant();

        Storage::fake('public');
        config(['ai.enabled' => true]);

        $this->model = new FakeStructuredModel;
        $this->app->instance(StructuredModel::class, $this->model);
    }

    /** @param array<string, mixed> $overrides */
    private function reading(array $overrides = []): array
    {
        return $overrides + [
            'supplier_name' => 'Pizza Hut Clifton',
            'supplier_ntn' => '1234567-8',
            'date' => '2026-10-20',
            'total' => '1,725.00',
            'tax' => '225.00',
            'currency' => 'PKR',
            'description' => 'Dinner, 4 covers',
            'account_code' => '5600',
            'confidence' => 0.9,
        ];
    }

    private function receipt(string $name = 'receipt.jpg'): string
    {
        Storage::disk('public')->put("invoice-attachments/{$name}", 'not really an image');

        return "invoice-attachments/{$name}";
    }

    public function test_a_receipt_becomes_a_draft_bill_for_the_supplier_found_by_ntn(): void
    {
        $supplier = Contact::create(['name' => 'Pizza Hut', 'kind' => Contact::KIND_SUPPLIER, 'ntn' => '1234567-8', 'payment_terms_days' => 15]);
        $path = $this->receipt();
        $this->model->queue($this->reading());

        $reader = app(ReceiptReader::class);
        $bill = $reader->draft($reader->read($path), $path);

        $this->assertSame(Invoice::KIND_PURCHASE, $bill->kind);
        $this->assertSame(Invoice::STATUS_DRAFT, $bill->status, 'nothing posts until a person issues it');
        $this->assertTrue($bill->contact->is($supplier), 'matched on the NTN, not the differently spelt name');
        $this->assertSame('2026-10-20', $bill->invoice_date->toDateString());
        $this->assertSame('2026-11-04', $bill->due_date->toDateString(), 'the supplier\'s own terms');
        $this->assertSame(1725.0, (float) $bill->total);
        $this->assertSame(225.0, (float) $bill->tax_amount);
        $this->assertSame(1500.0, (float) $bill->subtotal);
        $this->assertSame($path, $bill->attachment_path, 'the photo stays on the bill');

        $line = $bill->lines->first();
        $this->assertSame('Dinner, 4 covers', $line->description);
        $this->assertSame(1500.0, (float) $line->line_total);
        $this->assertSame(Account::where('code', '5600')->value('id'), $line->account_id);

        // The model saw the file as what it is.
        $this->assertSame('image/jpeg', $this->model->calls[0]['media_type']);
        $this->assertStringContainsString('5600: Meal Expense', $this->model->calls[0]['system']);
    }

    public function test_a_supplier_is_found_by_name_when_no_ntn_matches(): void
    {
        $bakers = Contact::create(['name' => 'Rahat Bakers', 'kind' => Contact::KIND_SUPPLIER]);
        $path = $this->receipt();
        $this->model->queue($this->reading(['supplier_name' => 'RAHAT BAKERS', 'supplier_ntn' => null]));

        $reader = app(ReceiptReader::class);
        $bill = $reader->draft($reader->read($path), $path);

        $this->assertTrue($bill->contact->is($bakers));
        $this->assertSame(1, Contact::count(), 'no duplicate was made');
    }

    public function test_an_unknown_supplier_is_created_and_flagged(): void
    {
        $path = $this->receipt();
        $this->model->queue($this->reading(['supplier_name' => 'Rahat Bakers', 'supplier_ntn' => '7654321-0']));

        $reader = app(ReceiptReader::class);
        $bill = $reader->draft($reader->read($path), $path);

        $this->assertSame('Rahat Bakers', $bill->contact->name);
        $this->assertSame(Contact::KIND_SUPPLIER, $bill->contact->kind);
        $this->assertSame('7654321-0', $bill->contact->ntn);
        $this->assertStringContainsString('is new, check it', $bill->memo);
    }

    /** A tax the size of the bill is a misread; the whole amount is shown and the person corrects it. */
    public function test_an_implausible_tax_is_dropped_rather_than_trusted(): void
    {
        $path = $this->receipt();
        $this->model->queue($this->reading(['total' => '1725', 'tax' => '1725']));

        $reader = app(ReceiptReader::class);
        $bill = $reader->draft($reader->read($path), $path);

        $this->assertSame(0.0, (float) $bill->tax_amount);
        $this->assertSame(1725.0, (float) $bill->subtotal);
    }

    public function test_a_receipt_with_no_readable_total_is_refused(): void
    {
        $path = $this->receipt();
        $this->model->queue($this->reading(['total' => null]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No total could be read');

        $reader = app(ReceiptReader::class);
        $reader->draft($reader->read($path), $path);
    }

    public function test_a_pdf_is_sent_as_a_document(): void
    {
        $path = $this->receipt('invoice.pdf');
        $this->model->queue($this->reading());

        app(ReceiptReader::class)->read($path);

        $this->assertSame('application/pdf', $this->model->calls[0]['media_type']);
    }

    public function test_the_local_driver_says_it_cannot_read_a_receipt(): void
    {
        $this->app->instance(StructuredModel::class, new LocalPatternModel);
        $path = $this->receipt();

        $this->expectException(ModelUnavailable::class);
        $this->expectExceptionMessage('Claude');

        app(ReceiptReader::class)->read($path);
    }

    public function test_the_bill_is_read_from_the_invoice_list(): void
    {
        $this->model->queue($this->reading());

        Livewire::test(ListInvoices::class)
            ->callAction('newBillFromReceipt', data: ['receipt' => UploadedFile::fake()->image('receipt.jpg')])
            ->assertNotified()
            ->assertRedirect();

        $bill = Invoice::query()->firstOrFail();
        $this->assertSame(Invoice::STATUS_DRAFT, $bill->status);
        $this->assertStringStartsWith('invoice-attachments/', (string) $bill->attachment_path);
        Storage::disk('public')->assertExists($bill->attachment_path);
    }
}
