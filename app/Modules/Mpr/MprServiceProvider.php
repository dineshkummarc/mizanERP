<?php

namespace App\Modules\Mpr;

use App\Modules\Mpr\Models\MPR;
use App\Modules\Mpr\Policies\MprPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the MPR module owns that Filament does not discover: its policies
 * and its routes.
 *
 * Policies are registered EXPLICITLY, and that is not a style preference.
 * Laravel guesses App\Models\X -> App\Policies\XPolicy; once a model lives in
 * App\Modules\Mpr\Models the guess never resolves, and Filament treats a model
 * with no policy as allowed. This exact class is the precedent: MprPolicy was
 * already registered by hand because the guess produced MPRPolicy, and on a
 * case-sensitive filesystem "the resource is open to everyone". Every module
 * provider therefore carries its own map, and ModuleCoverageTest fails the build
 * if a model behind a resource has no policy registered.
 */
class MprServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        MPR::class => MprPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->loadRoutesFrom(__DIR__.'/routes/api.php');
    }
}
