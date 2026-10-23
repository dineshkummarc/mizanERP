<?php

namespace App\Modules\Projects\Services;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\ProjectEnvironment;
use App\Modules\Projects\Models\ProjectEnvironmentIncident;
use App\Modules\Projects\Notifications\EnvironmentDown;
use App\Modules\Projects\Notifications\EnvironmentRecovered;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

/**
 * Turns a stream of check results into incidents and alerts.
 *
 * The rule that keeps alerts trustworthy: alert on confirmed state
 * transitions, never on individual check results. A single dropped request, a
 * deploy restart or a DNS blip opens an incident but never notifies anyone —
 * only crossing the failure threshold does. The cost is a confirmation delay
 * (3 failures at a 5-minute interval ≈ 10 minutes), which is the price of an
 * alert people still read after a month.
 */
class EnvironmentIncidentManager
{
    public function register(ProjectEnvironment $environment, EnvironmentCheckResult $result): void
    {
        $result->isUp
            ? $this->handleSuccess($environment)
            : $this->handleFailure($environment, $result);
    }

    protected function handleFailure(ProjectEnvironment $environment, EnvironmentCheckResult $result): void
    {
        $incident = $environment->openIncident();

        if (! $incident) {
            $incident = $environment->incidents()->create([
                'started_at' => now(),
                'failure_count' => 1,
                'last_error' => $result->error,
                'last_status_code' => $result->statusCode,
            ]);
        } else {
            $incident->update([
                'failure_count' => $incident->failure_count + 1,
                'last_error' => $result->error,
                'last_status_code' => $result->statusCode,
            ]);
        }

        $threshold = (int) config('projects.alerts.failure_threshold', 3);

        // Crossing the threshold for the first time is the only "down" alert.
        if (! $incident->isConfirmed() && $environment->consecutive_failures >= $threshold) {
            $incident->update(['confirmed_at' => now()]);

            $this->notify($environment, new EnvironmentDown($environment, $incident->refresh()));

            return;
        }

        if ($incident->isConfirmed()) {
            $this->maybeRemind($environment, $incident);
        }
    }

    protected function handleSuccess(ProjectEnvironment $environment): void
    {
        $incident = $environment->openIncident();

        if (! $incident) {
            return;
        }

        if ($environment->consecutive_successes < (int) config('projects.alerts.recovery_threshold', 2)) {
            return;
        }

        $wasConfirmed = $incident->isConfirmed();

        $incident->update(['resolved_at' => now()]);

        // Unconfirmed incidents resolve silently — nobody was told they broke.
        // Recovery alerts always send when a down alert did, even past the
        // reminder cap: an incident that looks unresolved is worse than one
        // extra email.
        if ($wasConfirmed) {
            $this->notify($environment, new EnvironmentRecovered($environment, $incident->refresh()));
        }
    }

    protected function maybeRemind(ProjectEnvironment $environment, ProjectEnvironmentIncident $incident): void
    {
        $max = (int) config('projects.alerts.max_reminders', 3);

        if ($incident->reminders_sent >= $max) {
            return;
        }

        $minutes = (int) config('projects.alerts.reminder_minutes', 60);
        $last = $incident->last_reminder_at ?? $incident->confirmed_at;

        if ($last && $last->diffInMinutes(now()) < $minutes) {
            return;
        }

        $incident->update([
            'reminders_sent' => $incident->reminders_sent + 1,
            'last_reminder_at' => now(),
        ]);

        $this->notify($environment, new EnvironmentDown($environment, $incident->refresh(), isReminder: true));
    }

    /**
     * Send to the project's managers, or to the fallback role when neither is
     * set — an alert nobody receives is the failure mode to avoid.
     */
    public function notify(ProjectEnvironment $environment, $notification): void
    {
        if (! $environment->alertsActive()) {
            return;
        }

        $recipients = $this->recipients($environment);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, $notification);
    }

    public function recipients(ProjectEnvironment $environment): Collection
    {
        $users = $environment->project?->notifiableUsers() ?? collect();

        if ($users->isNotEmpty()) {
            return $users;
        }

        return $this->fallbackUsers();
    }

    protected function fallbackUsers(): Collection
    {
        $roleName = config('projects.alerts.fallback_role');

        if (! $roleName) {
            return collect();
        }

        $role = Role::where('name', $roleName)->first();

        if (! $role) {
            return collect();
        }

        return User::whereHas('roles', fn ($query) => $query->whereKey($role->getKey()))->get();
    }
}
