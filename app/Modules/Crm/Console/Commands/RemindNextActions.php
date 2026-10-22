<?php

namespace App\Modules\Crm\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Crm\Models\NextAction;
use App\Modules\Crm\Services\NextActionReminders;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Tell each assignee what falls due today — `docs/bexio-gap-plan.md` Phase 2, item 6.
 *
 * Internal mail only: every recipient is a member of the company. `--dry-run` lists without sending.
 */
class RemindNextActions extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'crm:remind-next-actions
                            {--as-of= : Treat this date as today}
                            {--dry-run : List who would be reminded without sending anything}
                            {--tenant=* : One or more tenants to run for}';

    protected $description = 'Send each person a digest of the next actions that fall due today';

    public function handle(NextActionReminders $reminders): int
    {
        if ($this->skipsDisabledModule('crm')) {
            return self::SUCCESS;
        }

        $asOf = $this->option('as-of') ?: null;
        $due = $reminders->due($asOf);

        if ($due->isEmpty()) {
            $this->line('Nothing falls due that has not already been reminded.');

            return self::SUCCESS;
        }

        $this->table(
            ['Action', 'About', 'Due', 'Whose'],
            $due->map(fn (NextAction $action): array => [
                $action->title,
                $action->subject?->name ?? $action->subject?->display_label ?? '—',
                $action->effectiveDueOn()->toDateString(),
                $action->assignee?->display_label ?? '—',
            ])->all(),
        );

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing was sent.');

            return self::SUCCESS;
        }

        $sent = $reminders->send($asOf);

        $this->info("Reminded {$sent->pluck('assignee_employee_id')->unique()->count()} person(s) about {$sent->count()} action(s).");

        return self::SUCCESS;
    }
}
