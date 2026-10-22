<?php

namespace App\Notifications;

use App\Modules\Core\Models\Company;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceDeliveryService;
use App\Support\TemplatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An invoice or credit note, PDF attached, to the customer — `docs/bexio-gap-plan.md` Phase 1, item 2.
 *
 * Routed on demand to the contact's correspondence address, so mail is the only channel. The wording is
 * the company's own where it has written one (`EmailTemplate` key `invoice_issued`, used for credit notes
 * too — the placeholders are the same and a company chasing one document's wording will find the other
 * beside it).
 *
 * Nothing here changes the invoice. Sending is recorded as an `InvoiceEvent` by the delivery service, after
 * this has been queued, so a send that throws is not marked as done.
 */
class InvoiceIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invoice $invoice) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $delivery = app(InvoiceDeliveryService::class);

        $contact = $this->invoice->contact?->name ?? 'there';
        $number = $this->invoice->invoice_number;
        $amount = number_format((float) $this->invoice->total, 2);
        $due = $this->invoice->due_date?->format('j M Y');
        $company = Company::current()?->name;
        $kind = $this->invoice->isCreditNote() ? 'Credit note' : 'Invoice';

        $mail = TemplatedMail::apply(
            new MailMessage,
            'invoice_issued',
            [
                'contact_name' => $contact,
                'invoice_number' => $number,
                'amount' => $amount,
                'due_date' => $due,
                'company' => $company,
            ],
            subject: "{$kind} {$number}".($company ? " from {$company}" : ''),
            greeting: "Hello {$contact},",
            lines: $this->invoice->isCreditNote()
                ? [
                    "Credit note {$number} for {$amount} is attached. It reduces what you owe us by that amount.",
                    'If your records disagree with it, reply to this email quoting the credit note number.',
                ]
                : array_values(array_filter([
                    "Invoice {$number} for {$amount} is attached.",
                    $due ? "Payment is due by {$due}." : null,
                    'If anything on it needs correcting, reply to this email quoting the invoice number.',
                ])),
        );

        return $mail->attachData(
            $delivery->renderPdf($this->invoice)->raw(),
            $delivery->pdfFilename($this->invoice),
            ['mime' => 'application/pdf'],
        );
    }
}
