<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\ProjectEnvironment;
use App\Modules\Projects\Notifications\CertificateExpiring;
use App\Modules\Projects\Services\EnvironmentCertificateChecker;
use App\Modules\Projects\Services\EnvironmentIncidentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\MakesProjects;
use Tests\TestCase;

/**
 * The expiry-alert bookkeeping. The TLS handshake itself is not faked (it needs
 * a real socket), so these tests drive the threshold logic directly with a
 * known ssl_expires_at — which is where the once-per-threshold rule lives.
 */
class EnvironmentCertificateTest extends TestCase
{
    use MakesProjects;
    use RefreshDatabase;

    private ProjectEnvironment $environment;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['projects.ssl.thresholds' => [30, 14, 7, 3, 1]]);

        $this->manager = User::factory()->create();
        $employee = $this->makeEmployee('EMP-MGR', $this->manager);
        $project = $this->makeProject(['manager_employee_id' => $employee->id]);

        $this->environment = $this->makeEnvironment($project, ['url' => 'https://secure.example.test']);
    }

    /** Runs only the alerting half of the checker, with a known expiry. */
    private function evaluate(?int $daysOut): void
    {
        $this->environment->forceFill([
            'ssl_expires_at' => $daysOut === null ? null : now()->addDays($daysOut)->addHours(2),
            'ssl_checked_at' => now(),
        ])->save();

        $checker = new class(app(EnvironmentIncidentManager::class)) extends EnvironmentCertificateChecker
        {
            public function run(ProjectEnvironment $environment): void
            {
                $this->alertIfExpiring($environment);
            }
        };

        $checker->run($this->environment->refresh());
    }

    public function test_a_certificate_far_from_expiry_does_not_alert(): void
    {
        $this->evaluate(90);

        Notification::assertNothingSent();
        $this->assertNull($this->environment->refresh()->ssl_alerted_at_days);
    }

    public function test_each_threshold_alerts_once_not_daily(): void
    {
        // Crosses 30 days.
        $this->evaluate(29);
        Notification::assertSentToTimes($this->manager, CertificateExpiring::class, 1);
        $this->assertSame(30, (int) $this->environment->refresh()->ssl_alerted_at_days);

        // Still inside the same threshold on later days — no repeat.
        $this->evaluate(20);
        $this->evaluate(15);
        Notification::assertSentToTimes($this->manager, CertificateExpiring::class, 1);

        // Crossing the next threshold alerts again.
        $this->evaluate(13);
        Notification::assertSentToTimes($this->manager, CertificateExpiring::class, 2);
        $this->assertSame(14, (int) $this->environment->refresh()->ssl_alerted_at_days);
    }

    public function test_a_renewed_certificate_resets_the_alert_state(): void
    {
        $this->evaluate(5);
        $this->assertSame(7, (int) $this->environment->refresh()->ssl_alerted_at_days);

        // Renewed for another year.
        $this->evaluate(365);
        $this->assertNull($this->environment->refresh()->ssl_alerted_at_days);

        // And the thresholds fire again next time round.
        $this->evaluate(29);
        Notification::assertSentToTimes($this->manager, CertificateExpiring::class, 2);
    }

    public function test_a_non_https_url_is_skipped(): void
    {
        $plain = $this->makeEnvironment($this->makeProject(), ['url' => 'http://plain.example.test']);

        $this->assertFalse($plain->isHttps());
        $this->assertNull(app(EnvironmentCertificateChecker::class)->check($plain));
        Notification::assertNothingSent();
    }

    public function test_an_unreachable_tls_port_records_the_attempt_without_marking_it_down(): void
    {
        // Port 1 on a reserved-for-documentation address: refuses fast.
        $environment = $this->makeEnvironment($this->makeProject(), [
            'kind' => 'qual',
            'url' => 'https://192.0.2.1:1/',
        ]);

        config(['projects.ssl.timeout' => 1]);

        app(EnvironmentCertificateChecker::class)->check($environment);

        $environment->refresh();
        $this->assertNotNull($environment->ssl_checked_at);
        $this->assertNull($environment->ssl_expires_at);
        // Certificate problems never touch health — that is the HTTP check's job.
        $this->assertNull($environment->health_status);
    }
}
