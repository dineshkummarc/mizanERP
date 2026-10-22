<?php

namespace App\Notifications;

use App\Modules\Core\Models\Company;
use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Services\QuotationService;
use App\Support\TemplatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A quote, PDF attached, to whoever it is for — `docs/bexio-gap-plan.md` Phase 1, item 2.
 *
 * Routed on demand to an address on a Contact or a Lead, so mail is the only channel. The wording is the
 * company's own where it has written one (`EmailTemplate` key `quotation_issued`); the shipped text asks
 * for a reply rather than a click, because there is no portal for a customer to accept in.
 */
class QuotationIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Quotation $quotation) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $quotations = app(QuotationService::class);

        $name = $this->quotation->partyLabel();
        $number = $this->quotation->number;
        $total = number_format((float) $this->quotation->total, 2);
        $validUntil = $this->quotation->valid_until?->format('j M Y');
        $company = Company::current()?->name;

        $mail = TemplatedMail::apply(
            new MailMessage,
            'quotation_issued',
            [
                'contact_name' => $name,
                'quotation_number' => $number,
                'total' => $total,
                'valid_until' => $validUntil,
                'company' => $company,
            ],
            subject: "Quotation {$number}".($company ? " from {$company}" : ''),
            greeting: "Hello {$name},",
            lines: array_values(array_filter([
                "Quotation {$number} is attached, for a total of {$total}.",
                $validUntil ? "The prices in it hold until {$validUntil}." : null,
                'Reply to this email to accept it, or if anything in it needs changing.',
            ])),
        );

        return $mail->attachData(
            $quotations->renderPdf($this->quotation)->raw(),
            $quotations->pdfFilename($this->quotation),
            ['mime' => 'application/pdf'],
        );
    }
}
