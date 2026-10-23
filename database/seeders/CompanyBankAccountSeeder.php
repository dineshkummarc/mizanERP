<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\CompanyBankAccount;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Core\Models\Bank;
use Illuminate\Database\Seeder;

class CompanyBankAccountSeeder extends Seeder
{
    /**
     * The company's own operating accounts, each earmarked for a purpose.
     * The bank payment file debits these per transaction type.
     * Idempotent via updateOrCreate on account_no.
     */
    public function run()
    {
        $types = TransactionType::pluck('id', 'code');

        if ($types->isEmpty()) {
            $this->command?->warn('No transaction types found; run TransactionTypeSeeder first.');

            return;
        }

        $hbl = Bank::where('bank_short_code', 'HBL')->first();
        $mcb = Bank::where('bank_short_code', 'MCB')->first();

        $accounts = [
            [
                'account_no' => '1234567801',
                'title' => 'SCB Main Salary Account',
                'bank_id' => null, // Standard Chartered is the debiting bank (SCBLPKKXXXX), not in the IBFT directory
                'iban' => null,
                'transaction_type_id' => $types['salary'] ?? null,
                'is_default' => true,
            ],
            [
                'account_no' => '1234567802',
                'title' => 'HBL Rent & Facilities Account',
                'bank_id' => $hbl?->id,
                'iban' => 'PK55HABB0012345678020001',
                'transaction_type_id' => $types['rent'] ?? null,
                'is_default' => true,
            ],
            [
                'account_no' => '1234567803',
                'title' => 'MCB Operations Account',
                'bank_id' => $mcb?->id,
                'iban' => 'PK71MUCB0012345678030001',
                'transaction_type_id' => $types['miscellaneous'] ?? null,
                'is_default' => true,
            ],
        ];

        foreach ($accounts as $account) {
            CompanyBankAccount::updateOrCreate(
                ['account_no' => $account['account_no']],
                $account
            );
        }

        $this->command?->info('Seeded '.count($accounts).' company bank accounts.');
    }
}
