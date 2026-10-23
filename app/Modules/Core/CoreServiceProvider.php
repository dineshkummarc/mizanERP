<?php

namespace App\Modules\Core;

use App\Modules\Core\Console\Commands\DeliverScheduledReports;
use App\Modules\Core\Filament\Pages\ReportDeliveries;
use App\Modules\Core\Listeners\NotifyRecordAudience;
use App\Modules\Core\Listeners\SkipBroadcastWithoutBroadcaster;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Comment;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CustomField;
use App\Modules\Core\Models\EmailTemplate;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\Holiday;
use App\Modules\Core\Models\OptionValue;
use App\Modules\Core\Models\ReportDefinition;
use App\Modules\Core\Models\ReportSchedule;
use App\Modules\Core\Models\TableView;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\ActivityLogPolicy;
use App\Modules\Core\Policies\CommentPolicy;
use App\Modules\Core\Policies\CompanyPolicy;
use App\Modules\Core\Policies\CustomFieldPolicy;
use App\Modules\Core\Policies\EmailTemplatePolicy;
use App\Modules\Core\Policies\FiscalYearPolicy;
use App\Modules\Core\Policies\HolidayPolicy;
use App\Modules\Core\Policies\OptionValuePolicy;
use App\Modules\Core\Policies\PermissionPolicy;
use App\Modules\Core\Policies\ReportSchedulePolicy;
use App\Modules\Core\Policies\RolePolicy;
use App\Modules\Core\Policies\TableViewPolicy;
use App\Modules\Core\Policies\UserPolicy;
use App\Modules\Core\Services\HolidayCalendar;
use App\Support\Reporting\BuiltReport;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportDeliveryLog;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Everything the Core module owns that Filament does not discover.
 *
 * Policies are registered EXPLICITLY. Laravel guesses App\Models\X ->
 * App\Policies\XPolicy, which cannot resolve a model living in a module
 * directory, and Filament treats a model with no policy as allowed — so without
 * this map every resource here would be open to any authenticated user.
 * ModuleCoverageTest fails the build if one is missing.
 */
class CoreServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        ActivityLog::class => ActivityLogPolicy::class,
        Comment::class => CommentPolicy::class,
        Company::class => CompanyPolicy::class,
        CustomField::class => CustomFieldPolicy::class,
        FiscalYear::class => FiscalYearPolicy::class,
        Holiday::class => HolidayPolicy::class,
        OptionValue::class => OptionValuePolicy::class,
        ReportSchedule::class => ReportSchedulePolicy::class,
        TableView::class => TableViewPolicy::class,
        EmailTemplate::class => EmailTemplatePolicy::class,
        User::class => UserPolicy::class,

        // Vendor models Core owns the authorization for. The activity log is
        // spatie's Activity, not our ActivityLog wrapper, so it needs naming
        // separately or audit rows authorize as unpoliced.
        Activity::class => ActivityLogPolicy::class,
        Role::class => RolePolicy::class,
        Permission::class => PermissionPolicy::class,
    ];

    public function register(): void
    {
        // A singleton because its cache is only worth having if it is shared:
        // the leave-day generator resolves it once and loops, but attendance and
        // any validation in the same request resolve it again, and a fresh
        // instance each time reads the whole table each time.
        $this->app->singleton(HolidayCalendar::class);
    }

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->registerBuiltReports();
        $this->registerDeliveryLog();

        /*
         * The bell's general case: an audited change tells the people whose record it is.
         *
         * Hung off the activity row rather than off 157 models, because the audit trail is already the
         * answer to "what changed, to what, by whom" — see NotifyRecordAudience. `created` on the model
         * Spatie is configured to write through (config/activitylog.php) is every audited change in the
         * application, in one place.
         */
        ActivityLog::created(fn (ActivityLog $activity) => app(NotifyRecordAudience::class)($activity));

        // With no broadcaster configured, the broadcast channel is withdrawn from every notification before
        // it becomes a job that can only fail. See the listener.
        Event::listen(NotificationSending::class, SkipBroadcastWithoutBroadcaster::class);

        // Registered as well as scheduled: `Schedule::command()` in routes/console.php only wires the
        // timetable, and a command nobody can invoke by hand is a command nobody can test or re-run after a
        // failed night.
        $this->commands([DeliverScheduledReports::class]);

    }

    /**
     * Reports somebody assembled — `docs/reports-expansion-plan.md` Phase 6, item 4.
     *
     * A *family* rather than a renderer per report, because these keys are rows of `report_definitions` and
     * there is nothing to enumerate when a provider boots. Registered by Core because Core owns
     * `ReportDefinition`, exactly as each module registers the reports it owns — and Core is the module that
     * is always on, which is what makes a custom report available to a company that has bought nothing else.
     *
     * The pane, `NoReportPane` and every report page already ask `ReportRenderers`, so this one line is what
     * puts a built report on all three.
     */
    private function registerBuiltReports(): void
    {
        ReportRenderers::registerFamily(
            ReportDefinition::KEY_PREFIX,
            fn (string $key, string $asOf): ?array => app(BuiltReport::class)->forKey($key, $asOf),
        );
    }

    /**
     * The delivery log — `docs/reports-expansion-plan.md` Phase 8, item 8.
     *
     * A report like any other: registered into the hub, rendered by the module that owns it, drawn in the pane
     * and exported by Phase 4. Filed under *Operations* rather than with the financial statements, because
     * what it answers is "did the application do what it was told" and the person asking that is not reading
     * about money.
     */
    private function registerDeliveryLog(): void
    {
        ReportCatalogue::register(
            'Operations',
            ReportDeliveries::class,
            'Every scheduled report that went out, who it reached, and what failed.',
        );

        ReportRenderers::register(
            'ReportDeliveries',
            fn (string $asOf): array => app(ReportDeliveryLog::class)->for($asOf),
        );
    }
}
