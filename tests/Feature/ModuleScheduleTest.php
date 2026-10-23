<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Module schedules are loaded from routes/console.php, never through a provider's loadRoutesFrom().
 *
 * The framework's loadRoutesFrom() does nothing once routes are cached, and deploy.sh caches them on
 * every release — so for as long as the providers loaded their own schedules, production ran none of
 * them (found 2026-10-23). The console kernel requires routes/console.php regardless of the cache, and
 * that file now requires each module's. Both halves are pinned here: the first test fails the moment a
 * provider goes back to the gated method, the second the moment routes/console.php stops requiring.
 */
class ModuleScheduleTest extends TestCase
{
    public function test_no_module_provider_loads_its_schedule_through_the_route_cache(): void
    {
        $offenders = collect(glob(app_path('Modules/*/*ServiceProvider.php')))
            ->filter(fn (string $file): bool => str_contains(file_get_contents($file), "loadRoutesFrom(__DIR__.'/routes/console.php')"))
            ->map(fn (string $file): string => str_replace(base_path().'/', '', $file))
            ->values()
            ->all();

        $this->assertSame([], $offenders, implode("\n", [
            'loadRoutesFrom() is skipped when routes are cached, which production always are.',
            'routes/console.php requires every module schedule; a provider must not load its own.',
            '',
            ...$offenders,
        ]));
    }

    public function test_every_module_schedule_is_loaded(): void
    {
        $scheduled = collect(app(Schedule::class)->events())
            ->map(fn ($event): string => (string) ($event->command ?? ''))
            ->filter()
            ->values();

        // One from each of several modules, and a floor well under the count on the day it was written
        // (24), so a module dropping a job does not fail this but a glob that matched nothing does.
        foreach ([
            'invoicing:check-contact-documents', 'payroll:open-month', 'crm:remind-next-actions',
            'lifecycle:check-documents', 'reports:deliver', 'leave:open-year', 'projects:check-health',
        ] as $command) {
            $this->assertTrue(
                $scheduled->contains(fn (string $line): bool => str_contains($line, $command)),
                "{$command} is not scheduled.",
            );
        }

        $this->assertGreaterThanOrEqual(
            20,
            $scheduled->filter(fn (string $line): bool => ! preg_match('/health:|backup:|queue:work|model:prune/', $line))->count(),
            'far fewer module schedules than the twenty-four that existed when the loader was written',
        );
    }
}
