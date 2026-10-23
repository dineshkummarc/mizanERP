<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class JournalEntrySeeder extends Seeder
{
    /**
     * Demo journal entries covering every workflow state, created through
     * JournalEntryService so numbering, validation, and audit logging all
     * apply. Not for production.
     */
    public function run()
    {
        if (app()->environment('production')) {
            $this->command?->warn('JournalEntrySeeder skipped in production.');

            return;
        }

        if (JournalEntry::exists()) {
            $this->command?->info('Journal entries already present; skipping demo seed.');

            return;
        }

        $service = app(JournalEntryService::class);
        $fiscalYear = FiscalYear::where('is_active', true)->first();

        $maker = $this->userWithRole('Accountant', 'accountant@example.test', 'Demo Accountant');
        $approver = $this->userWithRole('Manager', 'manager@example.test', 'Demo Manager');

        $id = fn (string $code) => Account::where('code', $code)->firstOrFail()->id;

        $base = ['fiscal_year_id' => $fiscalYear?->id, 'created_by' => $maker->id];

        // 1. Posted month of activity (Jul-Aug 2026).
        $posted = [
            ['2026-07-05', 'Consulting invoice — client A', [
                ['account_id' => $id('1300'), 'debit_amount' => 1500000, 'description' => 'Invoice #INV-001'],
                ['account_id' => $id('4200'), 'credit_amount' => 1500000, 'description' => 'Invoice #INV-001'],
            ]],
            ['2026-07-07', 'Office rent July', [
                ['account_id' => $id('5700'), 'debit_amount' => 250000],
                ['account_id' => $id('1100'), 'credit_amount' => 250000],
            ]],
            ['2026-07-25', 'Client A payment received', [
                ['account_id' => $id('1100'), 'debit_amount' => 1500000],
                ['account_id' => $id('1300'), 'credit_amount' => 1500000],
            ]],
            ['2026-08-05', 'Utilities July', [
                ['account_id' => $id('5800'), 'debit_amount' => 85000],
                ['account_id' => $id('2400'), 'credit_amount' => 85000],
            ]],
            ['2026-08-07', 'Office rent August', [
                ['account_id' => $id('5700'), 'debit_amount' => 250000],
                ['account_id' => $id('1100'), 'credit_amount' => 250000],
            ]],
        ];

        foreach ($posted as [$date, $memo, $lines]) {
            $entry = $service->create($base + ['entry_date' => $date, 'memo' => $memo], $lines);
            $service->submitForApproval($entry);
            $service->approve($entry, $approver);
            $service->post($entry);
        }

        // 2. Pending approval.
        $pending = $service->create($base + ['entry_date' => '2026-09-05', 'memo' => 'Utilities August'], [
            ['account_id' => $id('5800'), 'debit_amount' => 90000],
            ['account_id' => $id('2400'), 'credit_amount' => 90000],
        ]);
        $service->submitForApproval($pending);

        // 3. Draft.
        $service->create($base + ['entry_date' => '2026-09-10', 'memo' => 'Office supplies (draft)'], [
            ['account_id' => $id('5900'), 'debit_amount' => 45000],
            ['account_id' => $id('1100'), 'credit_amount' => 45000],
        ]);

        // 4. Rejected with reason.
        $rejected = $service->create($base + ['entry_date' => '2026-09-12', 'memo' => 'Equipment purchase'], [
            ['account_id' => $id('1400'), 'debit_amount' => 380000],
            ['account_id' => $id('1100'), 'credit_amount' => 380000],
        ]);
        $service->submitForApproval($rejected);
        $service->reject($rejected, $approver, 'Missing vendor invoice — attach and resubmit.');

        // 5. Posted then reversed.
        $mistake = $service->create($base + ['entry_date' => '2026-08-20', 'memo' => 'Rent posted twice in error'], [
            ['account_id' => $id('5700'), 'debit_amount' => 250000],
            ['account_id' => $id('1100'), 'credit_amount' => 250000],
        ]);
        $service->submitForApproval($mistake);
        $service->approve($mistake, $approver);
        $service->post($mistake);
        $service->reverse($mistake->fresh(), $approver);

        $this->command?->info('Seeded '.JournalEntry::count().' demo journal entries across all workflow states.');
    }

    private function userWithRole(string $role, string $email, string $name): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make('password'), 'status' => 1]
        );

        $user->assignRole($role);

        return $user;
    }
}
