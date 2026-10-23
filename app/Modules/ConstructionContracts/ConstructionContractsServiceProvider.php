<?php

namespace App\Modules\ConstructionContracts;

use App\Modules\ConstructionContracts\Console\Commands\CheckComplianceExpiry;
use App\Modules\ConstructionContracts\Console\Commands\ReconcileRetention;
use App\Modules\ConstructionContracts\Filament\Settings\ConstructionAccountsSettingsSection;
use App\Modules\ConstructionContracts\Models\BackCharge;
use App\Modules\ConstructionContracts\Models\ComplianceDocument;
use App\Modules\ConstructionContracts\Models\ComplianceRequirement;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\ContractItem;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\ConstructionContracts\Models\ProgressClaim;
use App\Modules\ConstructionContracts\Models\RetentionMovement;
use App\Modules\ConstructionContracts\Models\Variation;
use App\Modules\ConstructionContracts\Policies\BackChargePolicy;
use App\Modules\ConstructionContracts\Policies\ComplianceDocumentPolicy;
use App\Modules\ConstructionContracts\Policies\ComplianceRequirementPolicy;
use App\Modules\ConstructionContracts\Policies\ContractItemPolicy;
use App\Modules\ConstructionContracts\Policies\ContractPolicy;
use App\Modules\ConstructionContracts\Policies\PaymentCertificatePolicy;
use App\Modules\ConstructionContracts\Policies\ProgressClaimPolicy;
use App\Modules\ConstructionContracts\Policies\RetentionMovementPolicy;
use App\Modules\ConstructionContracts\Policies\VariationPolicy;
use App\Support\SettingsSections;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything this module owns that Filament does not discover.
 *
 * Policies explicitly: Laravel's App\Models\X -> App\Policies\XPolicy guess cannot resolve a model in a module
 * directory, and Filament treats a model with no policy as allowed — so a missing registration is an open
 * resource. `ModuleCoverageTest` fails the build for one.
 */
class ConstructionContractsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        BackCharge::class => BackChargePolicy::class,
        ComplianceDocument::class => ComplianceDocumentPolicy::class,
        ComplianceRequirement::class => ComplianceRequirementPolicy::class,
        Contract::class => ContractPolicy::class,
        ContractItem::class => ContractItemPolicy::class,
        PaymentCertificate::class => PaymentCertificatePolicy::class,
        ProgressClaim::class => ProgressClaimPolicy::class,
        RetentionMovement::class => RetentionMovementPolicy::class,
        Variation::class => VariationPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        /*
         * The nightly retention reconciliation (§11) and the compliance expiry warning (§12). Licence-guarded inside
         * each command rather than here, because a schedule that changes shape with a company's licences is a schedule
         * nobody can read.
         */
        $this->commands([CheckComplianceExpiry::class, ReconcileRetention::class]);

        /*
         * The construction account map, contributed to Core's Company Settings page (§18.2).
         *
         * Registered from here because this is the module that owns the setting, and §18.2's refusal to add
         * `'core' => [… 'construction']` is what the `SettingsSections` registry exists to make possible. Sorted
         * after payroll posting, which is the other account-map block on that page.
         */
        SettingsSections::register('construction.accounts', ConstructionAccountsSettingsSection::class, 70);

        // The printed certificate (§10.5). Its own route rather than a panel action returning a response,
        // because a direct URL never consults `canAccess()` — the licence gate belongs on the route.
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
    }
}
