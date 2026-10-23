<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\ProjectEnvironment;
use App\Modules\Projects\Models\ProjectEnvironmentIncident;
use App\Modules\Projects\Notifications\EnvironmentDown;
use App\Modules\Projects\Notifications\EnvironmentRecovered;
use App\Modules\Projects\Services\EnvironmentHealthChecker;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\MakesProjects;
use Tests\TestCase;

/**
 * The flap-suppression state machine: alerts fire on confirmed transitions,
 * never on individual check results.
 */
class EnvironmentAlertingTest extends TestCase
{
    use MakesProjects;
    use RefreshDatabase;

    private ProjectEnvironment $environment;

    private User $manager;

    /**
     * One fake driven by a flag. Calling Http::fake() repeatedly would append
     * stubs and keep matching the first one, so a later "success" fake would
     * never take effect.
     */
    private bool $shouldFail = false;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Http::fake(fn () => Http::response('', $this->shouldFail ? 500 : 200));

        config([
            'projects.alerts.failure_threshold' => 3,
            'projects.alerts.recovery_threshold' => 2,
            'projects.alerts.reminder_minutes' => 60,
            'projects.alerts.max_reminders' => 3,
        ]);

        $this->manager = User::factory()->create();
        $employee = $this->makeEmployee('EMP-MGR', $this->manager);

        $project = $this->makeProject(['manager_employee_id' => $employee->id]);
        $this->environment = $this->makeEnvironment($project);
    }

    private function failCheck(int $times = 1): void
    {
        $this->runChecks($times, shouldFail: true);
    }

    private function succeed(int $times = 1): void
    {
        $this->runChecks($times, shouldFail: false);
    }

    private function runChecks(int $times, bool $shouldFail): void
    {
        $this->shouldFail = $shouldFail;

        for ($i = 0; $i < $times; $i++) {
            app(EnvironmentHealthChecker::class)->check($this->environment->refresh());
        }
    }

    public function test_two_failures_alert_nobody_but_open_an_unconfirmed_incident(): void
    {
        $this->failCheck(2);

        Notification::assertNothingSent();

        $incident = ProjectEnvironmentIncident::sole();
        $this->assertNull($incident->confirmed_at);
        $this->assertSame(2, (int) $incident->failure_count);
    }

    public function test_the_third_failure_confirms_and_sends_exactly_one_alert(): void
    {
        $this->failCheck(3);

        Notification::assertSentToTimes($this->manager, EnvironmentDown::class, 1);
        $this->assertNotNull(ProjectEnvironmentIncident::sole()->confirmed_at);
    }

    public function test_further_failures_inside_the_reminder_window_stay_quiet(): void
    {
        $this->failCheck(6);

        Notification::assertSentToTimes($this->manager, EnvironmentDown::class, 1);
    }

    public function test_a_reminder_is_sent_once_the_window_passes_and_stops_at_the_cap(): void
    {
        $this->failCheck(3);

        for ($reminder = 1; $reminder <= 4; $reminder++) {
            $this->travel(61)->minutes();
            $this->failCheck(1);
        }

        // 1 initial + 3 reminders, then silence.
        Notification::assertSentToTimes($this->manager, EnvironmentDown::class, 4);
        $this->assertSame(3, (int) ProjectEnvironmentIncident::sole()->reminders_sent);
    }

    public function test_one_success_does_not_resolve_but_the_second_does(): void
    {
        $this->failCheck(3);
        $this->succeed(1);

        $this->assertNull(ProjectEnvironmentIncident::sole()->resolved_at);
        Notification::assertNotSentTo($this->manager, EnvironmentRecovered::class);

        $this->succeed(1);

        $this->assertNotNull(ProjectEnvironmentIncident::sole()->resolved_at);
        Notification::assertSentToTimes($this->manager, EnvironmentRecovered::class, 1);
    }

    public function test_a_flap_never_alerts(): void
    {
        $this->failCheck(1);
        $this->succeed(2);
        $this->failCheck(1);
        $this->succeed(2);

        Notification::assertNothingSent();

        // Both flaps are recorded, so a wrong threshold is visible in the data.
        $this->assertSame(2, ProjectEnvironmentIncident::count());
        $this->assertSame(0, ProjectEnvironmentIncident::whereNotNull('confirmed_at')->count());
    }

    public function test_muting_suppresses_alerts_but_still_records_checks(): void
    {
        $this->environment->update(['muted_until' => now()->addHour()]);

        $this->failCheck(3);

        Notification::assertNothingSent();
        $this->assertNotNull(ProjectEnvironmentIncident::sole()->confirmed_at);
        $this->assertSame(3, $this->environment->checks()->count());
    }

    public function test_alerts_disabled_on_the_environment_silences_it(): void
    {
        $this->environment->update(['alerts_enabled' => false]);

        $this->failCheck(3);

        Notification::assertNothingSent();
    }

    public function test_the_global_kill_switch_silences_everything(): void
    {
        config(['projects.alerts.enabled' => false]);

        $this->failCheck(3);

        Notification::assertNothingSent();
    }

    public function test_the_secondary_manager_is_notified_too(): void
    {
        $secondaryUser = User::factory()->create();
        $secondary = $this->makeEmployee('EMP-SECOND', $secondaryUser);
        $this->environment->project->update(['secondary_employee_id' => $secondary->id]);

        $this->failCheck(3);

        Notification::assertSentTo($this->manager, EnvironmentDown::class);
        Notification::assertSentTo($secondaryUser, EnvironmentDown::class);
    }

    public function test_with_no_managers_the_fallback_role_is_notified(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->environment->project->update([
            'manager_employee_id' => null,
            'secondary_employee_id' => null,
        ]);

        $fallbackUser = User::factory()->create();
        $fallbackUser->assignRole('Manager');

        config(['projects.alerts.fallback_role' => 'Manager']);

        $this->failCheck(3);

        // An alert nobody receives is the failure mode this avoids.
        Notification::assertSentTo($fallbackUser, EnvironmentDown::class);
    }
}
