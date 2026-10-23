<?php

namespace App\Modules\Employees;

use App\Events\UserCreated;
use App\Modules\Employees\Console\Commands\ApplyDueJobChanges;
use App\Modules\Employees\Filament\Pages\HeadcountMovement;
use App\Modules\Employees\Listeners\CreateEmployeeForUser;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeChangeRequest;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Employees\Policies\EmployeeChangeRequestPolicy;
use App\Modules\Employees\Policies\EmployeePolicy;
use App\Modules\Employees\Policies\EmployeeSettingPolicy;
use App\Modules\Employees\Support\HeadcountReports;
use App\Support\CustomFieldSubjects;
use App\Support\DashboardStats;
use App\Support\ModuleMap;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportRenderers;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the Employees module owns that Filament does not discover.
 *
 * Policies are registered EXPLICITLY. Laravel guesses App\Models\X ->
 * App\Policies\XPolicy, which cannot resolve a model living in a module
 * directory, and Filament treats a model with no policy as allowed — so without
 * this map every resource here would be open to any authenticated user.
 * ModuleCoverageTest fails the build if one is missing.
 */
class EmployeesServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        EmployeeChangeRequest::class => EmployeeChangeRequestPolicy::class,
        Employee::class => EmployeePolicy::class,
        EmployeeSetting::class => EmployeeSettingPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerReports();

        // The records of this module that may carry custom fields. Registered by alias, which is what
        // `custom_fields.model_type` stores — see App\Support\CustomFieldSubjects.
        CustomFieldSubjects::register(ModuleMap::alias(Employee::class), 'Employees');

        // A new user gets an employee record. Registered here rather than done in Core's CreateUser page.
        Event::listen(UserCreated::class, CreateEmployeeForUser::class);

        DashboardStats::register('employees.active', fn () => auth()->user()?->can('EmployeeView')
            ? Stat::make('Employees', Employee::where('is_active', 1)->count())->description('active')
            : null, sort: 10);

        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->loadRoutesFrom(__DIR__.'/routes/api.php');

        // Laravel auto-discovers commands only in app/Console/Commands, so one
        // living in a module has to be registered here or it exists and cannot
        // be run — including by the schedule entry that names it.
        $this->commands([ApplyDueJobChanges::class]);
    }

    /**
     * The headcount report — `docs/reports-expansion-plan.md` Phase 3.6.
     *
     * Filed under *People & payroll* with the register and the liabilities: it is the report somebody opens
     * to ask how the workforce moved, and it reads against the payroll register for the same months.
     *
     * Registered unconditionally. The page gates itself on `moduleIsAvailable()` and `Reports::sections()`
     * filters through `canAccess()`.
     */
    private function registerReports(): void
    {
        ReportCatalogue::register(
            'People & payroll',
            HeadcountMovement::class,
            'Joiners and leavers per month, headcount, turnover, and how long the leavers stayed.',
        );

        ReportRenderers::register(
            'HeadcountMovement',
            fn (string $asOf): array => app(HeadcountReports::class)->movement($asOf),
        );
    }
}
