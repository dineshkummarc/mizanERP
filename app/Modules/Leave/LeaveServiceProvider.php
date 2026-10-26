<?php

namespace App\Modules\Leave;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Leave\Console\Commands\LapseCarriedDays;
use App\Modules\Leave\Console\Commands\OpenLeaveYear;
use App\Modules\Leave\Models\LeaveAdjustment;
use App\Modules\Leave\Models\LeaveDay;
use App\Modules\Leave\Models\LeaveEntitlement;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Policies\LeaveAdjustmentPolicy;
use App\Modules\Leave\Policies\LeaveDayPolicy;
use App\Modules\Leave\Policies\LeaveEntitlementPolicy;
use App\Modules\Leave\Policies\LeaveRequestPolicy;
use App\Modules\Leave\Policies\LeaveTypePolicy;
use App\Support\EmployeeAccess;
use App\Support\TenantFileAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the Leave module owns that Filament does not discover.
 *
 * Policies are registered EXPLICITLY. Laravel guesses App\Models\X ->
 * App\Policies\XPolicy, which cannot resolve a model living in a module directory,
 * and Filament treats a model with no policy as allowed — so without this map every
 * resource here would be open to any authenticated user. ModuleCoverageTest fails the
 * build if one is missing, which is how this was caught in this codebase before.
 */
class LeaveServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        LeaveType::class => LeaveTypePolicy::class,
        LeaveEntitlement::class => LeaveEntitlementPolicy::class,
        LeaveAdjustment::class => LeaveAdjustmentPolicy::class,
        LeaveRequest::class => LeaveRequestPolicy::class,
        LeaveDay::class => LeaveDayPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // leave-requests/ files stream only to the employee's own reporting line — security audit finding 10.
        TenantFileAccess::register(function (User $user, Company $company, string $path): ?bool {
            if (! str_starts_with($path, 'leave-requests/')) {
                return null;
            }

            $lookup = fn () => LeaveRequest::query()->where('document_path', $path)->first();
            $switch = ! Company::current()?->is($company) && filled(config('multitenancy.tenant_database_connection_name'));
            $record = $switch ? $company->execute($lookup) : $lookup();

            if (! $record) {
                return null;
            }

            $access = app(EmployeeAccess::class);

            return $access->isPrivileged($user) || $access->accessibleEmployeeIds($user)->contains($record->employee_id);
        });

        $this->loadRoutesFrom(__DIR__.'/routes/api.php');

        // Laravel only auto-discovers commands in app/Console/Commands, so a command
        // living in a module has to be registered here or it disappears from artisan
        // — and from the scheduler, silently.
        $this->commands([OpenLeaveYear::class, LapseCarriedDays::class]);
    }
}
