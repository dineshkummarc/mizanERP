<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridApiTransport;
use Symfony\Component\Mailer\Transport\FailoverTransport;
use Tests\TestCase;

/**
 * Production mail: Mailgun, then SendGrid, and nothing quieter after them.
 *
 * The chain is configuration, so what is pinned is the configuration and that each name in it
 * resolves to the real API transport — a typo in a transport name is otherwise found by the
 * first password reset nobody receives.
 */
class MailFailoverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sendgrid.key' => 'SG.test-key',
            'services.mailgun.domain' => 'mg.example.test',
            'services.mailgun.secret' => 'key-test',
        ]);
    }

    public function test_the_failover_chain_is_mailgun_then_sendgrid_and_nothing_else(): void
    {
        // Mailgun leads — it authenticates as mg.erbium.ch, so mail leaves with aligned SPF/DKIM;
        // SendGrid is the standby, tried only if Mailgun throws. See config/mail.php.
        $this->assertSame(['mailgun', 'sendgrid'], config('mail.mailers.failover.mailers'));
    }

    public function test_sendgrid_resolves_to_its_api_transport(): void
    {
        $this->assertInstanceOf(SendgridApiTransport::class, Mail::mailer('sendgrid')->getSymfonyTransport());
    }

    /**
     * Every mailer in the chain resolves, whichever transport it is configured with.
     *
     * Not pinned to a transport class, deliberately: the second provider may be reached over its HTTP
     * API or over SMTP, and that is a deployment choice. What must never happen is the production
     * failure this file exists for — `Mailer [mailgun] is not defined`, thrown at send time by a chain
     * naming a mailer nothing defines.
     */
    public function test_every_mailer_in_the_chain_resolves(): void
    {
        foreach (config('mail.mailers.failover.mailers') as $mailer) {
            $this->assertNotNull(
                Mail::mailer($mailer)->getSymfonyTransport(),
                "the [{$mailer}] mailer is named in the failover chain and must be defined",
            );
        }
    }

    public function test_the_chain_builds_with_mailgun_ahead_of_sendgrid(): void
    {
        config(['mail.mailers.mailgun.host' => 'smtp.eu.mailgun.org']);

        $transport = Mail::mailer('failover')->getSymfonyTransport();

        $this->assertInstanceOf(FailoverTransport::class, $transport);

        // The order is the decision: the second provider is only ever tried after the first has thrown.
        $description = (string) $transport;
        $this->assertLessThan(strpos($description, 'sendgrid'), strpos($description, 'mailgun'), $description);
        $this->assertStringNotContainsString('log', $description, 'no silent last resort');
    }
}
