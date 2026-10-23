<?php

namespace App\Modules\ConstructionQhse;

use App\Modules\ConstructionQhse\Console\Commands\CheckCompetencyExpiry;
use App\Modules\ConstructionQhse\Models\Incident;
use App\Modules\ConstructionQhse\Models\Inspection;
use App\Modules\ConstructionQhse\Models\Itp;
use App\Modules\ConstructionQhse\Models\Ncr;
use App\Modules\ConstructionQhse\Models\Permit;
use App\Modules\ConstructionQhse\Models\QhseAction;
use App\Modules\ConstructionQhse\Models\SitePersonnel;
use App\Modules\ConstructionQhse\Models\ToolboxTalk;
use App\Modules\ConstructionQhse\Policies\IncidentPolicy;
use App\Modules\ConstructionQhse\Policies\InspectionPolicy;
use App\Modules\ConstructionQhse\Policies\ItpPolicy;
use App\Modules\ConstructionQhse\Policies\NcrPolicy;
use App\Modules\ConstructionQhse\Policies\PermitPolicy;
use App\Modules\ConstructionQhse\Policies\QhseActionPolicy;
use App\Modules\ConstructionQhse\Policies\SitePersonnelPolicy;
use App\Modules\ConstructionQhse\Policies\ToolboxTalkPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything this module owns that Filament does not discover.
 *
 * Policies explicitly: Laravel's App\Models\X -> App\Policies\XPolicy guess cannot resolve a model in a module
 * directory, and Filament treats a model with no policy as allowed — so a missing registration is an open resource.
 * `ModuleCoverageTest` fails the build for one.
 */
class ConstructionQhseServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Itp::class => ItpPolicy::class,
        Inspection::class => InspectionPolicy::class,
        Ncr::class => NcrPolicy::class,
        QhseAction::class => QhseActionPolicy::class,
        Incident::class => IncidentPolicy::class,
        Permit::class => PermitPolicy::class,
        SitePersonnel::class => SitePersonnelPolicy::class,
        ToolboxTalk::class => ToolboxTalkPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // Registered as well as scheduled: `Schedule::command()` in routes/console.php only wires the timetable, and a
        // command nobody can invoke by hand is a command nobody can test or re-run after a failed night.
        $this->commands([CheckCompetencyExpiry::class]);

    }
}
