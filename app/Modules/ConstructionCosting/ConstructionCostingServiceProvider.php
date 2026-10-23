<?php

namespace App\Modules\ConstructionCosting;

use App\Modules\ConstructionCosting\Console\Commands\Reconcile;
use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Models\ControlAccount;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Models\GlPosting;
use App\Modules\ConstructionCosting\Models\GoodsReceipt;
use App\Modules\ConstructionCosting\Models\JobBudget;
use App\Modules\ConstructionCosting\Models\LabourRate;
use App\Modules\ConstructionCosting\Models\LabourRecord;
use App\Modules\ConstructionCosting\Models\MaterialIssue;
use App\Modules\ConstructionCosting\Models\PlantItem;
use App\Modules\ConstructionCosting\Models\PlantLog;
use App\Modules\ConstructionCosting\Models\Reconciliation;
use App\Modules\ConstructionCosting\Models\Requisition;
use App\Modules\ConstructionCosting\Models\Trade;
use App\Modules\ConstructionCosting\Models\WipSnapshot;
use App\Modules\ConstructionCosting\Models\Worker;
use App\Modules\ConstructionCosting\Policies\CommitmentPolicy;
use App\Modules\ConstructionCosting\Policies\ControlAccountPolicy;
use App\Modules\ConstructionCosting\Policies\CostEntryPolicy;
use App\Modules\ConstructionCosting\Policies\CostPeriodPolicy;
use App\Modules\ConstructionCosting\Policies\GlPostingPolicy;
use App\Modules\ConstructionCosting\Policies\GoodsReceiptPolicy;
use App\Modules\ConstructionCosting\Policies\JobBudgetPolicy;
use App\Modules\ConstructionCosting\Policies\LabourRatePolicy;
use App\Modules\ConstructionCosting\Policies\LabourRecordPolicy;
use App\Modules\ConstructionCosting\Policies\MaterialIssuePolicy;
use App\Modules\ConstructionCosting\Policies\PlantItemPolicy;
use App\Modules\ConstructionCosting\Policies\PlantLogPolicy;
use App\Modules\ConstructionCosting\Policies\ReconciliationPolicy;
use App\Modules\ConstructionCosting\Policies\RequisitionPolicy;
use App\Modules\ConstructionCosting\Policies\TradePolicy;
use App\Modules\ConstructionCosting\Policies\WipSnapshotPolicy;
use App\Modules\ConstructionCosting\Policies\WorkerPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the cost ledger owns that Filament does not discover.
 *
 * Policies explicitly: Laravel's App\Models\X -> App\Policies\XPolicy guess cannot resolve a model in a module
 * directory, and Filament treats a model with no policy as allowed — so a missing registration is an open
 * resource. `ModuleCoverageTest` fails the build for one.
 */
class ConstructionCostingServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        JobBudget::class => JobBudgetPolicy::class,
        Commitment::class => CommitmentPolicy::class,
        CostEntry::class => CostEntryPolicy::class,
        CostPeriod::class => CostPeriodPolicy::class,
        GoodsReceipt::class => GoodsReceiptPolicy::class,
        Requisition::class => RequisitionPolicy::class,
        Trade::class => TradePolicy::class,
        Worker::class => WorkerPolicy::class,
        LabourRate::class => LabourRatePolicy::class,
        LabourRecord::class => LabourRecordPolicy::class,
        MaterialIssue::class => MaterialIssuePolicy::class,
        PlantItem::class => PlantItemPolicy::class,
        PlantLog::class => PlantLogPolicy::class,
        // §4's machinery, from Phase 11.
        ControlAccount::class => ControlAccountPolicy::class,
        GlPosting::class => GlPostingPolicy::class,
        Reconciliation::class => ReconciliationPolicy::class,
        WipSnapshot::class => WipSnapshotPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // Registered as well as scheduled: `Schedule::command()` in routes/console.php only wires the timetable, and a
        // command nobody registered cannot be run by hand — which is the first thing anybody wants to do with it.
        $this->commands([Reconcile::class]);

    }
}
