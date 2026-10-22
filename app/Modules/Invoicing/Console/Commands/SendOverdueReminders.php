<?php

namespace App\Modules\Invoicing\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Invoicing\Services\OverdueReminderService;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Email the customers whose invoices are overdue — `docs/erpnext-gap-plan.md` Phase 5.
 *
 * Off unless the company has switched dunning on, which the service enforces rather than this command: a
 * person running it by hand must not be able to send what the scheduler would not.
 *
 * `--dry-run` prints the list and sends nothing, and it is the option to use first. This is the only
 * scheduled job in the application that writes to somebody outside the company.
 */
class SendOverdueReminders extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'invoicing:send-overdue-reminders
                            {--as-of= : Treat this date as today}
                            {--dry-run : List who would be chased without sending anything}
                            {--tenant=* : One or more tenants to run for}';

    protected $description = 'Email customers whose invoices are past due';

    public function handle(OverdueReminderService $reminders): int
    {
        if ($this->skipsDisabledModule('invoicing')) {
            return self::SUCCESS;
        }

        if (! $reminders->enabled()) {
            $this->line('Overdue reminders are switched off for this company (Settings → Chasing overdue invoices).');

            return self::SUCCESS;
        }

        $asOf = $this->option('as-of') ?: null;
        $due = $reminders->due($asOf);

        if ($due->isEmpty()) {
            $this->line('Nothing is due a reminder: '.$reminders->afterDays().' days past due, '
                .'not chased in the last '.$reminders->repeatDays().'.');

            return self::SUCCESS;
        }

        $this->table(
            ['Invoice', 'Customer', 'Email', 'Due', 'Days', 'Outstanding', 'Reminder'],
            $due->map(fn (array $row): array => [
                $row['invoice']->invoice_number,
                $row['invoice']->contact?->name ?? '—',
                $row['invoice']->contact?->correspondenceEmail() ?? '—',
                $row['invoice']->due_date?->toDateString() ?? '—',
                $row['days'],
                number_format($row['invoice']->outstanding(), 2),
                $row['level'],
            ])->all(),
        );

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing was sent.');

            return self::SUCCESS;
        }

        $sent = $reminders->send($asOf);

        $this->info("Reminded {$sent->count()} customer(s). Each reminder is recorded on the invoice's own history.");

        return self::SUCCESS;
    }
}
