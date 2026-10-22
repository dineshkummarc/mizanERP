<?php

namespace App\Notifications;

use App\Modules\Core\Models\Company;
use App\Modules\Crm\Models\NextAction;
use App\Support\Broadcasting;
use App\Support\TemplatedMail;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Your next actions for today, in one message — `docs/bexio-gap-plan.md` Phase 2, item 6.
 *
 * A digest rather than one notification per action, which is `docs/crms-plan.md` §9's rule. Goes to
 * a member of the company, so it takes every channel the other internal notifications take; the
 * wording is the company's own where it has written one (`EmailTemplate` key `next_action_due`).
 */
class NextActionDue extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  Collection<int, NextAction>  $actions */
    public function __construct(public Collection $actions, public ?string $url = null) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return Broadcasting::channels(['mail', 'database', 'broadcast']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = $this->actions->count();

        $mail = TemplatedMail::apply(
            new MailMessage,
            'next_action_due',
            [
                'employee_name' => $notifiable->name,
                'count' => (string) $count,
                'company' => Company::current()?->name,
            ],
            subject: $count === 1 ? 'One next action falls due today' : "{$count} next actions fall due today",
            greeting: "Hello {$notifiable->name},",
            lines: [
                'Due today:',
                ...$this->actions->map(fn (NextAction $action): string => '• '.$this->describe($action))->all(),
            ],
        );

        return $this->url ? $mail->action('Open your list', $this->url) : $mail;
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $count = $this->actions->count();

        $notification = FilamentNotification::make()
            ->title($count === 1 ? 'One next action falls due today' : "{$count} next actions fall due today")
            ->body($this->actions->take(3)->map(fn (NextAction $action): string => $this->describe($action))->implode(' · ')
                .($count > 3 ? ' · …' : ''));

        if ($this->url) {
            $notification->actions([Action::make('open')->label('Open')->url($this->url)]);
        }

        return $notification->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    /** "Call about the pricing — Karachi Textiles": the action and who it is about. */
    private function describe(NextAction $action): string
    {
        $subject = $action->subject;
        $about = $subject?->name ?? $subject?->display_label ?? null;

        return $action->title.($about ? " — {$about}" : '');
    }
}
