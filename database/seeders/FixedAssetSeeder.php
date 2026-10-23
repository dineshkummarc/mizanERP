<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Services\DepreciationService;
use App\Modules\Core\Models\FiscalYear;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class FixedAssetSeeder extends Seeder
{
    /**
     * Demo asset register: realistic assets with mixed depreciation methods
     * and useful lives. After creating the assets, runs
     * DepreciationService::runForMonth() for each elapsed month of the active
     * fiscal year so the 1500/5990 ledgers and book values carry real data.
     * Idempotent: firstOrCreate on asset_code; the service skips months a
     * depreciation entry is already posted for.
     */
    public function run()
    {
        $fiscalYear = FiscalYear::where('is_active', true)->first()
            ?? FiscalYear::where('name', '2026-2027')->first();

        if (! $fiscalYear) {
            $this->command?->warn('No fiscal year found; run FiscalYearSeeder first.');

            return;
        }

        $equipment = Account::where('code', '1400')->first();
        $vehicles = Account::where('code', '1450')->first() ?? $equipment;

        if (! $equipment) {
            $this->command?->warn('Account 1400 not found; run ChartOfAccountsSeeder first.');

            return;
        }

        $fyStart = Carbon::parse($fiscalYear->start_date);

        $assets = [
            ['asset_code' => 'FA-LAP-001', 'name' => 'MacBook Pro 16" (Dev Team)', 'account_id' => $equipment->id, 'purchase_date' => $fyStart->copy()->subMonths(6), 'purchase_cost' => 850000, 'depreciation_method' => 'straight_line', 'useful_life_months' => 36, 'salvage_value' => 100000],
            ['asset_code' => 'FA-LAP-002', 'name' => 'Dell XPS 15 (Design Team)', 'account_id' => $equipment->id, 'purchase_date' => $fyStart->copy()->subMonths(3), 'purchase_cost' => 450000, 'depreciation_method' => 'straight_line', 'useful_life_months' => 36, 'salvage_value' => 50000],
            ['asset_code' => 'FA-FUR-001', 'name' => 'Office Furniture (Workstations & Chairs)', 'account_id' => $equipment->id, 'purchase_date' => $fyStart->copy()->subMonths(12), 'purchase_cost' => 1200000, 'depreciation_method' => 'straight_line', 'useful_life_months' => 96, 'salvage_value' => 0],
            ['asset_code' => 'FA-SRV-001', 'name' => 'Dell PowerEdge Server + UPS', 'account_id' => $equipment->id, 'purchase_date' => $fyStart->copy(), 'purchase_cost' => 1500000, 'depreciation_method' => 'declining_balance', 'useful_life_months' => 48, 'salvage_value' => 150000],
            ['asset_code' => 'FA-VEH-001', 'name' => 'Toyota Corolla (Company Car)', 'account_id' => $vehicles->id, 'purchase_date' => $fyStart->copy()->subMonths(9), 'purchase_cost' => 6500000, 'depreciation_method' => 'straight_line', 'useful_life_months' => 120, 'salvage_value' => 2000000],
        ];

        $created = 0;

        foreach ($assets as $data) {
            $data['purchase_date'] = $data['purchase_date']->toDateString();

            $asset = FixedAsset::firstOrCreate(
                ['asset_code' => $data['asset_code']],
                $data
            );

            if ($asset->wasRecentlyCreated) {
                $created++;
            }
        }

        // Book depreciation for each elapsed month of the active fiscal year.
        $service = app(DepreciationService::class);
        $month = $fyStart->copy()->startOfMonth();
        $end = Carbon::now()->startOfMonth();
        $fiscalEnd = Carbon::parse($fiscalYear->end_date)->startOfMonth();

        if ($end->greaterThan($fiscalEnd)) {
            $end = $fiscalEnd;
        }

        $entriesBooked = 0;

        while ($month->lessThanOrEqualTo($end)) {
            $entriesBooked += count($service->runForMonth($month, $fiscalYear->id));
            $month->addMonth();
        }

        $this->command?->info("Seeded {$created} fixed assets; booked {$entriesBooked} depreciation entries for fiscal year {$fiscalYear->name}.");
    }
}
