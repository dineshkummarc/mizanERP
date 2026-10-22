<?php

namespace App\Modules\Invoicing\Services;

use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceEvent;
use App\Notifications\InvoiceIssued;
use App\Support\Pdf\Pdf;
use App\Support\Pdf\PdfDocument;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * Getting an invoice in front of the customer — `docs/bexio-gap-plan.md` Phase 1, item 2.
 *
 * Until this, invoices were download-only: somebody produced the PDF, opened their mail client, and the
 * application recorded `printed` because that was the closest thing to "sent" it could witness. Now it
 * sends, and records that it did — so "when did we send this, and where?" has an answer on the document.
 *
 * **Deliberate rather than automatic**, like payslips. Issuing posts and reports to FBR; sending is a
 * separate act, because an issued invoice is sometimes handed over in person, and because a resend is a
 * normal request ("could you send that again?") that must not need the invoice to change state.
 *
 * **Email only.** The payslip service also sends on WhatsApp, through a provider template approved for
 * payslips; an invoice through that template would arrive captioned as one. WhatsApp for customer
 * documents waits for a template of its own — Phase 1 says so.
 */
class InvoiceDeliveryService
{
    public function renderPdf(Invoice $invoice): PdfDocument
    {
        // creditedInvoice: a credit note prints the number of the invoice it reverses.
        $invoice->loadMissing(['contact', 'lines.product', 'creditedInvoice']);

        return Pdf::view('pdfs.invoice', ['invoice' => $invoice])->name($this->pdfFilename($invoice));
    }

    public function pdfFilename(Invoice $invoice): string
    {
        return "{$invoice->invoice_number}.pdf";
    }

    /**
     * Email the document to the customer, PDF attached.
     *
     * The event is written after the notification is queued, so a send that throws leaves no record
     * claiming it went — the same order the overdue reminders use, for the same reason.
     *
     * @return string the address it went to
     *
     * @throws InvalidArgumentException when there is nothing to send, or nobody to send it to
     */
    public function send(Invoice $invoice): string
    {
        if (! in_array($invoice->kind, [Invoice::KIND_SALE, Invoice::KIND_CREDIT_NOTE], true)) {
            throw new InvalidArgumentException(
                'Only a customer document is emailed from here — a bill or debit note is something the supplier sent us.'
            );
        }

        if ($invoice->isDraft()) {
            throw new InvalidArgumentException('Issue it first: a draft has posted nothing and may still change.');
        }

        if ($invoice->status === Invoice::STATUS_VOID) {
            throw new InvalidArgumentException('A voided document is not sent to anybody.');
        }

        $email = $invoice->contact?->correspondenceEmail();

        if (blank($email)) {
            throw new InvalidArgumentException('The customer has no email address on record. Add one to the contact first.');
        }

        Notification::route('mail', $email)->notify(new InvoiceIssued($invoice));

        InvoiceEvent::record(
            $invoice,
            InvoiceEvent::SENT,
            ($invoice->isCreditNote() ? 'Credit note' : 'Invoice')." emailed to {$email}",
        );

        return $email;
    }
}
