<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Core\Models\Bank;
use Illuminate\Database\Seeder;

class BeneficiarySeeder extends Seeder
{
    /**
     * Non-employee payees that appear in the bank payment file alongside
     * salaries: landlord, caterer, utility provider, fuel station.
     * Idempotent via firstOrCreate on name.
     */
    public function run()
    {
        $types = TransactionType::pluck('id', 'code');

        if ($types->isEmpty()) {
            $this->command?->warn('No transaction types found; run TransactionTypeSeeder first.');

            return;
        }

        $bank = fn (string $shortCode) => Bank::where('bank_short_code', $shortCode)->first()?->id;

        $beneficiaries = [
            [
                'name' => 'Mr. Landlord (Office Premises)',
                'bank_id' => $bank('MCB'),
                'iban' => 'PK36MUCB0001234567890123',
                'id_type' => 'CNIC',
                'id_number' => '42101-1234567-1',
                'address_line_1' => 'Office Plaza, Clifton',
                'address_line_2' => 'Karachi',
                'phone' => '0300-1234567',
                'transaction_type_id' => $types['rent'] ?? null,
                'payment_type' => 'IBFT',
            ],
            [
                'name' => 'Evergreen Catering Services',
                'bank_id' => $bank('HBL'),
                'iban' => 'PK40HABB0009876543210987',
                'id_type' => 'NTN',
                'id_number' => '7654321-8',
                'address_line_1' => 'Shahrah-e-Faisal',
                'address_line_2' => 'Karachi',
                'phone' => '021-34567890',
                'transaction_type_id' => $types['food'] ?? null,
                'payment_type' => 'IBFT',
            ],
            [
                'name' => 'Skyline Internet (Pvt) Ltd',
                'bank_id' => $bank('UBL'),
                'iban' => 'PK62UNIL0112233445566778',
                'id_type' => 'NTN',
                'id_number' => '1122334-5',
                'address_line_1' => 'I.I. Chundrigar Road',
                'address_line_2' => 'Karachi',
                'phone' => '021-111222333',
                'transaction_type_id' => $types['utilities'] ?? null,
                'payment_type' => 'IBFT',
            ],
            [
                'name' => 'Office Boy (Petty Cash Custodian)',
                'bank_id' => $bank('UBL'),
                'iban' => 'PK15UNIL0198765432109876',
                'id_type' => 'CNIC',
                'id_number' => '42201-7654321-3',
                'address_line_1' => 'Shahrah-e-Faisal',
                'address_line_2' => 'Karachi',
                'phone' => '0301-7654321',
                'transaction_type_id' => $types['petty-cash-replenishment'] ?? null,
                'payment_type' => 'IBFT',
                'is_petty_cash_custodian' => true,
            ],
            [
                'name' => 'Crescent Fuel Station',
                'bank_id' => $bank('NBP'),
                'account_no' => '4455667788990011',
                'id_type' => 'NTN',
                'id_number' => '9988776-2',
                'address_line_1' => 'Korangi Road, DHA',
                'address_line_2' => 'Karachi',
                'phone' => '021-35801234',
                'transaction_type_id' => $types['fuel'] ?? null,
                'payment_type' => 'IBFT',
            ],
        ];

        $created = 0;

        foreach ($beneficiaries as $beneficiary) {
            $model = Beneficiary::firstOrCreate(
                ['name' => $beneficiary['name']],
                $beneficiary
            );

            if ($model->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->command?->info("Seeded beneficiaries: {$created} created, ".(count($beneficiaries) - $created).' already existed.');
    }
}
