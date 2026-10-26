<?php

namespace App\Modules\Leave\Notifications;

use App\Modules\Leave\Models\LeaveRequest;
use App\Support\Broadcasting;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells whoever may decide that there is leave waiting.
 *
 * On the transition, once — not a daily digest of the same pending request. The
 * health-check alerts taught that lesson here already: a job that mails the same
 * person the same warning for thirty days trains them to filter it.
 */
class LeaveRequestSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    /**   string|null  $awaiting  the chain role this notice is for, when a step has already been given */
    public function __construct(public LeaveRequest $request, public ?string $awaiting = null) {}

    public function via(object $notifiable): array
    {
        return Broadcasting::channels(['mail', 'database', 'broadcast']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("{$this->employeeName()} has requested {$this->typeLabel()}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->employeeName()} has requested {$this->typeLabel()} for {$this->range()}.")
            ->line("That is {$this->days()} working day(s).");

        if ($this->request->reason) {
            $mail->line('Reason: '.$this->request->reason);
        }

        // Named, never attached. A medical certificate is reachable only to somebody
        // signed in with access to this company, which an email is not — and §7 is
        // explicit that an approver seeing the document is a policy decision rather
        // than an accident.
        if ($this->request->document_path) {
            $mail->line('A supporting document was attached — open the request to see it.');
        }

        if ($this->awaiting) {
            $mail->line("It has its earlier approval and now waits for {$this->awaiting}.");
        }

        return $mail->line('Approve it, or refuse it with a reason, from Leave Requests.');
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title("{$this->employeeName()} requested {$this->typeLabel()}")
            ->body("{$this->range()} — {$this->days()} day(s)")
            ->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    private function employeeName(): string
    {
        return $this->request->employee?->user?->name
            ?? $this->request->employee?->name
            ?? 'An employee';
    }

    private function typeLabel(): string
    {
        return $this->request->leaveType?->label ?? 'leave';
    }

    private function range(): string
    {
        $from = $this->request->from_date->format('d M Y');

        if ($this->request->is_half_day) {
            return "{$from} (half day)";
        }

        return $this->request->from_date->isSameDay($this->request->to_date)
            ? $from
            : $from.' to '.$this->request->to_date->format('d M Y');
    }

    private function days(): string
    {
        return rtrim(rtrim(number_format((float) $this->request->days, 1), '0'), '.');
    }
}
