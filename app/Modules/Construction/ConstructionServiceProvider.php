<?php

namespace App\Modules\Construction;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Document;
use App\Modules\Construction\Models\DocumentRevision;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Policies\CostCodePolicy;
use App\Modules\Construction\Policies\DocumentPolicy;
use App\Modules\Construction\Policies\JobPolicy;
use App\Modules\Construction\Support\CostCodeCsvImporter;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Support\CsvImporters;
use App\Support\TenantFileAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the Construction spine owns that Filament does not discover.
 *
 * Policies are registered EXPLICITLY. Laravel guesses App\Models\X -> App\Policies\XPolicy, which cannot
 * resolve a model living in a module directory, and Filament treats a model with no policy as allowed — so
 * without this map every resource here would be open to any authenticated user. `ModuleCoverageTest` fails
 * the build if one is missing.
 *
 * See docs/construction-management-plan.md §18 for what this module is and why it requires nothing.
 */
class ConstructionServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Job::class => JobPolicy::class,
        CostCode::class => CostCodePolicy::class,
        Document::class => DocumentPolicy::class,
    ];

    public function boot(): void
    {
        // The cost-code library, loaded from a spreadsheet on Core's existing import screen. This is how
        // codes arrive rather than a convenience: Phase 0 settled that no proprietary code list ships, since
        // MasterFormat, Uniclass, NRM and ICMS are all somebody else's to license. See CostCodeCsvImporter.
        CsvImporters::register('construction_cost_codes', CostCodeCsvImporter::class, 40);

        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // A restricted document's file streams only to somebody DocumentPolicy lets view it — the
        // construction plan's §15.2, enforced rather than advisory. Answered on the company in the URL,
        // which need not be the current tenant, and null for a path no revision owns.
        TenantFileAccess::register(function (User $user, Company $company, string $path): ?bool {
            $lookup = fn () => DocumentRevision::query()->where('file_path', $path)->with('document')->first();
            // Switched to the URL's company only where there is a separate tenant connection to switch to —
            // the single-database setup (and its test suite) has every company on one connection already.
            $switch = ! Company::current()?->is($company) && filled(config('multitenancy.tenant_database_connection_name'));
            $revision = $switch ? $company->execute($lookup) : $lookup();

            return $revision?->document ? $user->can('view', $revision->document) : null;
        });
    }
}
