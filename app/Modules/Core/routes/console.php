<?php

use App\Modules\Core\Console\Commands\DeliverScheduledReports;
use Illuminate\Support\Facades\Schedule;

/**
 * Scheduled and emailed reports — `docs/reports-expansion-plan.md` Phase 8, item 5.
 *
 * **One entry for every schedule in every company.** The command asks which schedules are due and dispatches
 * only those, which is the `CheckEnvironmentsHealth` shape and the reason a thousand schedules still need one
 * line here rather than a scheduler entry per row.
 *
 * Every fifteen minutes, so `ReportSchedule::isDueAt()` matches against a fifteen-minute window: a report set
 * for 07:00 fires on the 07:00 run and not four times an hour. Finer would mean more work for no gain — nobody
 * schedules a report to the minute — and coarser would make a timetable's stated time a rough one.
 *
 * **This needs `schedule:run` on cron AND a running queue worker** (`QUEUE_CONNECTION` defaults to
 * `database`). Said out loud because the failure is otherwise silent in the worst way: the schedules are
 * listed, they say when they will next run, and nothing ever arrives. `report_deliveries` is where to look —
 * no rows means the command never ran, rows stuck at `pending` means the worker is not consuming.
 */
Schedule::command(DeliverScheduledReports::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping();

/**
 * The audit trail's retention, applied.
 *
 * `config/activitylog.php` has said "keep 365 days" since the log was added, and nothing ever ran the
 * command that enforces it — 6,887 rows after one month of development, and it is a landlord table shared
 * by every company, so it grows with all of them at once. It is also, since the record-change
 * notifications, the table the bell hangs off; a table nothing prunes is a query that gets slower forever.
 *
 * Nightly, in the quiet hours, and never two at once. **** because the command is confirmable
 * (ConfirmableTrait) and refuses to run unattended in production without it — without the flag the scheduled
 * job exited 1 every night and the retention never ran, which is the unbounded growth it exists to stop.
 */
Schedule::command('activitylog:clean --force')
    ->dailyAt('03:15')
    ->withoutOverlapping();
