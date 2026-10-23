<?php

namespace App\Notifications;

use App\Modules\Core\Models\Company;
use App\Modules\Invoicing\Models\ContactDocument;
use App\Support\Broadcasting;
use App\Support\TemplatedMail;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A contact's contract or document is about to lapse — docs/bexio-gap-plan.md §3.7.
 *
 * Sent once per threshold crossed, never once per day: Lifecycle's DocumentExpiring for the other kind
 * of document. Goes to a member, so it takes every internal channel, and the wording is the company's
 * own where it has written one (EmailTemplate key `contact_document_expiring`).
 */
class ContactDocumentExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ContactDocument $document, public int $days) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return Broadcasting::channels(['mail', 'database', 'broadcast']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return TemplatedMail::apply(
            new MailMessage,
            'contact_document_expiring',
            [
                'employee_name' => $notifiable->name,
                'contact_name' => $this->contactName(),
                'document' => $this->document->title,
                'expires_on' => $this->document->expires_on?->format('d M Y') ?? '',
                'company' => Company::current()?->name,
            ],
            subject: $this->subject(),
            greeting: "Hello {$notifiable->name},",
            lines: [$this->body(), 'Open the contact\'s Documents tab to record the renewal.'],
        );
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $notification = FilamentNotification::make()->title($this->subject())->body($this->body());

        $this->days < 0 ? $notification->danger() : $notification->warning();

        return $notification->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    private function contactName(): string
    {
        return $this->document->contact?->name ?? 'a contact';
    }

    /** "Expired 40 days ago" and "expires in 40 days" are different problems, and the subject is where that is decided. */
    private function subject(): string
    {
        $what = $this->document->kindLabel().' "'.$this->document->title.'" for '.$this->contactName();

        return $this->days < 0
            ? "{$what} has EXPIRED"
            : "{$what} expires in {$this->days} day(s)";
    }

    private function body(): string
    {
        $on = $this->document->expires_on?->format('d M Y');

        return $this->days < 0
            ? "It lapsed on {$on}, ".abs($this->days).' day(s) ago.'
            : "It lapses on {$on}.";
    }
}
