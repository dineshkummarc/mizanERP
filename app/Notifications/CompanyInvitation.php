<?php

namespace App\Notifications;

use App\Support\TemplatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "You have been given access to this company" — `docs/bexio-gap-plan.md` Phase 2, item 10.
 *
 * Two shapes from one class. A brand-new account gets a link to set its password — a reset token,
 * because the panel's reset screen is already the page that turns a token into a password, and
 * building a second one for invitations would be the same page with a different heading. Somebody who
 * already had an account is told the company now appears in their list, and gets the sign-in page.
 */
class CompanyInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  array<int, string>  $roles */
    public function __construct(
        public string $company,
        public array $roles,
        public string $inviter,
        public string $url,
        public bool $setsPassword,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $roles = implode(', ', $this->roles);

        $mail = TemplatedMail::apply(
            new MailMessage,
            'company_invitation',
            [
                'name' => $notifiable->name,
                'company' => $this->company,
                'roles' => $roles,
                'inviter' => $this->inviter,
            ],
            subject: "{$this->inviter} has given you access to {$this->company}",
            greeting: "Hello {$notifiable->name},",
            lines: [
                "{$this->inviter} has added you to {$this->company} as {$roles}.",
                $this->setsPassword
                    ? 'Set a password with the button below to sign in for the first time. The link works for one hour; '
                        .'after that, use "Forgot password" on the sign-in page with this email address.'
                    : 'Sign in with the password you already use — the company now appears in your list.',
            ],
        );

        return $mail->action($this->setsPassword ? 'Set your password' : 'Sign in', $this->url);
    }
}
