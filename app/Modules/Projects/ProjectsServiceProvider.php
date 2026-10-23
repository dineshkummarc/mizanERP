<?php

namespace App\Modules\Projects;

use App\Modules\Employees\Filament\Resources\Employees\EmployeeResource;
use App\Modules\Employees\Models\Employee;
use App\Modules\Projects\Console\Commands\CheckEnvironmentCertificates;
use App\Modules\Projects\Console\Commands\CheckEnvironmentsHealth;
use App\Modules\Projects\Filament\Pages\EnvironmentHealth;
use App\Modules\Projects\Filament\RelationManagers\EmployeeProjectsRelationManager;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Policies\ProjectPolicy;
use App\Modules\Projects\Support\EnvironmentReports;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportRenderers;
use App\Support\ResourceContributions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the Projects module owns that Filament does not discover.
 *
 * Policies are registered EXPLICITLY. Laravel guesses App\Models\X ->
 * App\Policies\XPolicy, which cannot resolve a model living in a module
 * directory, and Filament treats a model with no policy as allowed — so without
 * this map every resource here would be open to any authenticated user.
 * ModuleCoverageTest fails the build if one is missing.
 */
class ProjectsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Project::class => ProjectPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->contributeToEmployees();

        $this->loadRoutesFrom(__DIR__.'/routes/web.php');

        // Laravel only auto-discovers commands in app/Console/Commands,
        // so a moved command has to be registered here or it disappears
        // from artisan — and from the scheduler, silently.
        $this->commands([CheckEnvironmentCertificates::class, CheckEnvironmentsHealth::class]);

        $this->registerReports();
    }

    /**
     * The environment health report — `docs/reports-expansion-plan.md` Phase 3.13.
     *
     * Filed under *Operations* with the other service-delivery reports: whoever reads an SLA breach is the
     * person who wants to know how long production was down.
     *
     * Registered unconditionally. The page gates itself on `moduleIsAvailable()` and `Reports::sections()`
     * filters through `canAccess()`, so a company without the projects module sees no entry rather than a
     * report that fails when opened.
     */
    private function registerReports(): void
    {
        ReportCatalogue::register(
            'Operations',
            EnvironmentHealth::class,
            'Uptime, failed checks and outages per environment — the history behind the health widgets.',
        );

        ReportRenderers::register(
            'EnvironmentHealth',
            fn (string $asOf): array => app(EnvironmentReports::class)->environmentHealth($asOf),
        );
    }

    /**
     * What Projects adds to the Employees module, rather than what Employees knows about Projects.
     *
     * `projects` requires `employees`, so this direction is a declared dependency and free; the reverse
     * was a cycle, and a cycle is the one coupling composer cannot express. So everything that used to
     * make an Employee know about a Project lives here now:
     *
     *  - the three relations, added to the model at boot rather than declared on it. `Model::__call()`
     *    consults the relation resolvers before falling through to the query builder
     *    (`vendor/laravel/framework/.../Model.php:2833`), so `$employee->projects()` keeps working
     *    exactly as before — and stops existing when this module is not installed, which is correct.
     *  - the Projects tab on the Employee screen, through the slot EmployeeResource offers.
     *
     * Registered unconditionally, like every other provider in this application: one deployment serves
     * every company and the tenant is resolved per request, so the *licence* check belongs on the
     * resource (BelongsToModule) rather than here. What this guarantees is the weaker and more useful
     * property — if the Projects **package** is absent, none of it is registered at all.
     */
    private function contributeToEmployees(): void
    {
        Employee::resolveRelationUsing(
            'projects',
            fn (Employee $employee) => $employee->belongsToMany(Project::class, 'project_employee')
                ->withPivot(['id', 'role', 'allocation_pct', 'from_date', 'to_date'])
                ->withTimestamps(),
        );

        Employee::resolveRelationUsing(
            'managedProjects',
            fn (Employee $employee) => $employee->hasMany(Project::class, 'manager_employee_id'),
        );

        Employee::resolveRelationUsing(
            'secondaryProjects',
            fn (Employee $employee) => $employee->hasMany(Project::class, 'secondary_employee_id'),
        );

        ResourceContributions::addRelationManager(
            EmployeeResource::class,
            EmployeeProjectsRelationManager::class,
        );
    }
}
