<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\PettyCashVoucher;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Services\PettyCashService;
use App\Modules\Core\Models\FiscalYear;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class PettyCashSeeder extends Seeder
{
    /**
     * For each elapsed month of the active fiscal year: a float top-up and
     * the reference sheet's vouchers (Cleaning 1,000 · Stationery 1,000 ·
     * Travel 500 · Cleaning 1,000). Completed months are replenished so the
     * custodian's top-up shows in the bank payment file. Idempotent: skips
     * months that already have vouchers. Deterministic amounts.
     */
    public function run()
    {
        $fiscalYear = FiscalYear::where('is_active', true)->first();

        if (! $fiscalYear) {
            $this->command?->warn('No active fiscal year; run FiscalYearSeeder first.');

            return;
        }

        $service = app(PettyCashService::class);

        $cleaning = TransactionType::byCode('cleaning');
        $stationery = TransactionType::byCode('office-supplies');
        $travel = TransactionType::byCode('fuel');

        if (! $cleaning || ! $stationery || ! $travel) {
            $this->command?->warn('Transaction types missing; run TransactionTypeSeeder first.');

            return;
        }

        $month = Carbon::parse($fiscalYear->start_date)->startOfMonth();
        $end = now()->startOfMonth();
        $fiscalEnd = Carbon::parse($fiscalYear->end_date)->startOfMonth();

        if ($end->greaterThan($fiscalEnd)) {
            $end = $fiscalEnd;
        }

        $monthsSeeded = 0;
        $replenished = 0;

        while ($month->lessThanOrEqualTo($end)) {
            $hasVouchers = PettyCashVoucher::whereBetween('date', [
                $month->toDateString(), $month->copy()->endOfMonth()->toDateString(),
            ])->exists();

            if (! $hasVouchers) {
                // Fund the float on the 1st when the box is below the imprest.
                $shortfall = $service->floatAmount() - $service->balanceAsOf($month->toDateString());

                if ($shortfall > 0) {
                    $service->topUp($month->toDateString(), $shortfall, 'Cash');
                }

                foreach ([
                    ['day' => 5, 'details' => 'Cleaning', 'amount' => 1000, 'type' => $cleaning],
                    ['day' => 10, 'details' => 'Stationery', 'amount' => 1000, 'type' => $stationery],
                    ['day' => 15, 'details' => 'Travel expense', 'amount' => 500, 'type' => $travel],
                    ['day' => 20, 'details' => 'Cleaning', 'amount' => 1000, 'type' => $cleaning],
                ] as $voucher) {
                    $date = $month->copy()->day($voucher['day']);

                    if ($date->greaterThan(now())) {
                        continue; // don't book future vouchers in the current month
                    }

                    $service->bookVoucher([
                        'date' => $date->toDateString(),
                        'details' => $voucher['details'],
                        'amount' => $voucher['amount'],
                        'transaction_type_id' => $voucher['type']->id,
                    ]);
                }

                $monthsSeeded++;
            }

            // Close months that have fully elapsed.
            if ($month->copy()->endOfMonth()->isPast() && ! $service->isMonthReplenished($month)) {
                $service->replenish($month);
                $replenished++;
            }

            $month->addMonth();
        }

        $this->command?->info("Petty cash: seeded {$monthsSeeded} month(s), replenished {$replenished}.");
    }
}
