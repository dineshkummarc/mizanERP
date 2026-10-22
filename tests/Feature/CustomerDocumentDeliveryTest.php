<?php

namespace Tests\Feature;

use App\Modules\Core\Models\EmailTemplate;
use App\Modules\Crm\Models\Lead;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceEvent;
use App\Modules\Invoicing\Services\InvoiceDeliveryService;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Modules\Quotations\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Services\QuotationService;
use App\Notifications\InvoiceIssued;
use App\Notifications\QuotationIssued;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Documents leaving the building — `docs/bexio-gap-plan.md` Phase 1, items 1 and 2.
 *
 * Until this, an invoice was a PDF somebody downloaded and a quote had no PDF at all. The assertions that
 * carry the file are the refusals — a draft, a bill, a customer with no address — and the one that says
 * the send is on the invoice's history, which is where somebody chasing a late payment will look.
 */
class CustomerDocumentDeliveryTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'sales@test.local'));
        $this->setCurrentTenant();

        Notification::fake();

        $this->customer = Contact::create([
            'name' => 'Karachi Textiles',
            'kind' => Contact::KIND_CUSTOMER,
            'email' => 'accounts@karachitextiles.test',
        ]);
    }

    // ─────────────────────────────────────────────────── invoices ──

    public function test_an_issued_invoice_is_emailed_to_the_customer_and_recorded(): void
    {
        $invoice = $this->issuedInvoice();

        $email = app(InvoiceDeliveryService::class)->send($invoice);

        $this->assertSame('accounts@karachitextiles.test', $email);
        Notification::assertSentOnDemand(
            InvoiceIssued::class,
            fn (InvoiceIssued $n, array $channels, object $notifiable): bool => $n->invoice->is($invoice)
                && $notifiable->routes['mail'] === 'accounts@karachitextiles.test',
        );

        $event = $invoice->events()->where('event', InvoiceEvent::SENT)->firstOrFail();
        $this->assertSame('Invoice emailed to accounts@karachitextiles.test', $event->description);
    }

    /** The email carries the document, named by its number, and the shipped wording names the due date. */
    public function test_the_invoice_email_attaches_the_pdf(): void
    {
        $invoice = $this->issuedInvoice();

        $mail = (new InvoiceIssued($invoice))->toMail($invoice->contact);

        $this->assertStringStartsWith("Invoice {$invoice->invoice_number}", $mail->subject);
        $this->assertStringContainsString('Payment is due by', implode(' ', $mail->introLines));
        $this->assertCount(1, $mail->rawAttachments);
        $this->assertSame("{$invoice->invoice_number}.pdf", $mail->rawAttachments[0]['name']);
        $this->assertStringStartsWith('%PDF', $mail->rawAttachments[0]['data']);
    }

    /** A second send is a second entry, not a refusal: "could you send that again?" is routine. */
    public function test_a_resend_is_recorded_as_its_own_event(): void
    {
        $invoice = $this->issuedInvoice();
        $delivery = app(InvoiceDeliveryService::class);

        $delivery->send($invoice);
        $delivery->send($invoice);

        $this->assertSame(2, $invoice->events()->where('event', InvoiceEvent::SENT)->count());
    }

    public function test_a_draft_is_not_emailed(): void
    {
        $invoice = $this->invoice();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Issue it first');

        app(InvoiceDeliveryService::class)->send($invoice);
    }

    public function test_a_bill_is_not_emailed(): void
    {
        $bill = $this->invoice(Invoice::KIND_PURCHASE);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('supplier sent us');

        app(InvoiceDeliveryService::class)->send($bill);
    }

    public function test_a_customer_with_no_email_is_refused_and_nothing_is_recorded(): void
    {
        $this->customer->update(['email' => null]);
        $invoice = $this->issuedInvoice();

        try {
            app(InvoiceDeliveryService::class)->send($invoice);
            $this->fail('A customer with no address should have been refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('no email address', $e->getMessage());
        }

        Notification::assertNothingSent();
        $this->assertSame(0, $invoice->events()->where('event', InvoiceEvent::SENT)->count());
    }

    public function test_a_company_can_write_its_own_invoice_email(): void
    {
        EmailTemplate::create([
            'key' => 'invoice_issued',
            'subject' => 'Your invoice {invoice_number} for {amount}',
            'body' => 'Dear {contact_name}, please pay by {due_date}.',
            'is_active' => true,
        ]);

        $invoice = $this->issuedInvoice();

        $mail = (new InvoiceIssued($invoice))->toMail($invoice->contact);

        $this->assertSame("Your invoice {$invoice->invoice_number} for 100,000.00", $mail->subject);
        $this->assertStringContainsString('Dear Karachi Textiles, please pay by', implode(' ', $mail->introLines));
    }

    /** The list is where invoicing works, so the button is on the row — and it goes through the service. */
    public function test_the_invoice_is_emailed_from_its_row(): void
    {
        $invoice = $this->issuedInvoice();

        Livewire::test(ListInvoices::class)
            ->callAction(TestAction::make('email')->table($invoice->getKey()))
            ->assertNotified('Emailed to accounts@karachitextiles.test.');

        Notification::assertSentOnDemand(InvoiceIssued::class);
        $this->assertSame(1, $invoice->events()->where('event', InvoiceEvent::SENT)->count());
    }

    // ─────────────────────────────────────────────────── quotations ──

    /** Send on the row fixes the figures and emails the PDF in one press. */
    public function test_the_quote_is_sent_and_emailed_from_its_row(): void
    {
        $quote = $this->quote();

        Livewire::test(ListQuotations::class)
            ->callAction(TestAction::make('send')->table($quote->getKey()))
            ->assertNotified('Sent to accounts@karachitextiles.test.');

        $this->assertTrue($quote->refresh()->isSent());
        Notification::assertSentOnDemand(QuotationIssued::class);
    }

    /** Without an address the quote is still sent — the offer stands — and the screen says it was not emailed. */
    public function test_a_quote_without_an_address_is_marked_sent_but_not_emailed(): void
    {
        $this->customer->update(['email' => null]);
        $quote = $this->quote();

        Livewire::test(ListQuotations::class)
            ->callAction(TestAction::make('send')->table($quote->getKey()))
            ->assertNotified('Marked as sent, but not emailed.');

        $this->assertTrue($quote->refresh()->isSent());
        Notification::assertNothingSent();
    }

    public function test_a_sent_quote_is_emailed_with_its_pdf(): void
    {
        $quote = $this->quote();
        $service = app(QuotationService::class);

        $service->send($quote);
        $email = $service->deliver($quote);

        $this->assertSame('accounts@karachitextiles.test', $email);
        Notification::assertSentOnDemand(
            QuotationIssued::class,
            fn (QuotationIssued $n, array $channels, object $notifiable): bool => $n->quotation->is($quote)
                && $notifiable->routes['mail'] === 'accounts@karachitextiles.test',
        );

        // The PDF names the quote, the line and the validity — rendered as HTML, which both engines share.
        $html = $service->renderPdf($quote)->html();
        $this->assertStringContainsString($quote->number, $html);
        $this->assertStringContainsString('Warehouse system', $html);
        $this->assertStringContainsString('Valid until', $html);
        $this->assertSame("{$quote->number}.pdf", $service->pdfFilename($quote));
    }

    public function test_a_draft_quote_is_not_emailed(): void
    {
        $quote = $this->quote();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Send it first');

        app(QuotationService::class)->deliver($quote);
    }

    /** A quote to a prospect goes to the prospect: the lead's address, since there is no contact yet. */
    public function test_a_quote_to_a_lead_goes_to_the_lead(): void
    {
        $lead = Lead::create(['company_name' => 'Lahore Looms', 'email' => 'buyer@lahorelooms.test']);
        $quote = $this->quote(['contact_id' => null, 'lead_id' => $lead->getKey()]);
        $service = app(QuotationService::class);

        $service->send($quote);

        $this->assertSame('buyer@lahorelooms.test', $service->deliver($quote));
    }

    public function test_a_quote_for_a_party_with_no_email_is_refused(): void
    {
        $this->customer->update(['email' => null]);
        $quote = $this->quote();
        $service = app(QuotationService::class);

        $service->send($quote);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('customer has no email address');

        $service->deliver($quote);
    }

    public function test_a_company_can_write_its_own_quote_email(): void
    {
        EmailTemplate::create([
            'key' => 'quotation_issued',
            'subject' => 'Our offer {quotation_number}: {total}',
            'is_active' => true,
        ]);

        $quote = $this->quote();
        app(QuotationService::class)->send($quote);

        $mail = (new QuotationIssued($quote->refresh()))->toMail($quote->contact);

        $this->assertSame("Our offer {$quote->number}: 200,000.00", $mail->subject);
        $this->assertSame("{$quote->number}.pdf", $mail->rawAttachments[0]['name']);
    }

    // ───────────────────────────────────────────────────── fixtures ──

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

    private function issuedInvoice(): Invoice
    {
        $invoice = $this->invoice();
        app(InvoiceService::class)->issue($invoice);

        return $invoice->refresh();
    }

    /** @param  array<string, mixed>  $attributes */
    private function quote(array $attributes = []): Quotation
    {
        $quote = Quotation::create(array_merge([
            'contact_id' => $this->customer->getKey(),
            'valid_until' => now()->addDays(30)->toDateString(),
        ], $attributes));

        $quote->lines()->create(['description' => 'Warehouse system', 'quantity' => 2, 'unit_price' => 100_000]);

        return $quote->fresh('lines')->recalculate();
    }
}
