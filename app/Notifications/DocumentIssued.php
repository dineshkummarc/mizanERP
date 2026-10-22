<?php

namespace App\Notifications;

use App\Modules\Core\Models\Company;
use App\Support\TemplatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A document for the person it is about, attached — `docs/bexio-gap-plan.md` Phase 5, item 18.
 *
 * A tax deduction certificate, an income certificate: things an employee asks for and attaches to a
 * return or a loan application. Mail only, because the point is the attachment; the wording is the
 * company's own where it has written one (`EmailTemplate` key `document_issued`).
 *
 * Carries the bytes rather than re-rendering on the queue: a certificate is issued as of the day it was
 * made, and one re-rendered tomorrow from a corrected payslip would not be the document that was sent.
 */
class DocumentIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $document,
        public string $filename,
        public string $bytes,
        public ?string $note = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $notifiable->name ?? 'there';
        $company = Company::current()?->name;

        $mail = TemplatedMail::apply(
            new MailMessage,
            'document_issued',
            [
                'employee_name' => $name,
                'document' => $this->document,
                'company' => $company,
            ],
            subject: $this->document.($company ? " from {$company}" : ''),
            greeting: "Hello {$name},",
            lines: array_values(array_filter([
                "Your {$this->document} is attached.",
                $this->note,
                'If anything on it is wrong, reply to this email and it will be reissued.',
            ])),
        );

        return $mail->attachData($this->bytes, $this->filename, ['mime' => 'application/pdf']);
    }
}
