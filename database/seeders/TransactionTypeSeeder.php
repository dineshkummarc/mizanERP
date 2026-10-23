<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\TransactionType;
use Illuminate\Database\Seeder;

class TransactionTypeSeeder extends Seeder
{
    /**
     * Payment/expense categories, each mapped to its default chart account.
     * Idempotent via code.
     */
    public function run()
    {
        $types = [
            ['code' => 'salary', 'name' => 'Salary', 'account_code' => '5100', 'description' => 'Monthly payroll'],
            ['code' => 'rent', 'name' => 'Rent', 'account_code' => '5700', 'description' => 'Office rent'],
            ['code' => 'food', 'name' => 'Food', 'account_code' => '5600', 'description' => 'Meals & catering'],
            ['code' => 'utilities', 'name' => 'Utilities', 'account_code' => '5750', 'description' => 'Electricity, internet, phone'],
            ['code' => 'fuel', 'name' => 'Fuel', 'account_code' => '5800', 'description' => 'Fuel & travel'],
            ['code' => 'office-supplies', 'name' => 'Office Supplies', 'account_code' => '5850', 'description' => 'Stationery & consumables'],
            ['code' => 'equipment', 'name' => 'Equipment', 'account_code' => '1400', 'description' => 'Fixed asset purchases'],
            ['code' => 'tax-payment', 'name' => 'Tax Payment', 'account_code' => '2100', 'description' => 'FBR withholding tax remittance'],
            ['code' => 'cleaning', 'name' => 'Cleaning', 'account_code' => '5860', 'description' => 'Cleaning & janitorial'],
            ['code' => 'petty-cash-replenishment', 'name' => 'Petty Cash Replenishment', 'account_code' => '1150', 'description' => 'Restores the petty cash imprest'],
            ['code' => 'miscellaneous', 'name' => 'Miscellaneous', 'account_code' => '5900', 'description' => 'Everything else'],
            // A supplier bill paid through the bank file — docs/bexio-gap-plan.md Phase 3. The account is
            // informational: the bill posts its own settlement against Accounts Payable (BillPaymentService).
            ['code' => 'supplier-bill', 'name' => 'Supplier bill', 'account_code' => '2400', 'description' => 'Settles a supplier bill through the bank file; the bill itself carries the expense'],
            // An approved expense claim paid by bank transfer — docs/bexio-gap-plan.md §3.6. Informational
            // likewise: the claim clears the liability its approval booked (ExpenseClaimBankPayment).
            ['code' => 'expense-reimbursement', 'name' => 'Expense reimbursement', 'account_code' => '2310', 'description' => 'Pays an approved expense claim by bank transfer; the claim itself carries the expense'],

            /*
             * Money coming in.
             *
             * These were missing, and the absence was invisible for as long as this list was only ever
             * read by the payment screens — a payment is money going out by definition, so nothing ever
             * asked for a receipt's category. Anything category-driven that works in both directions
             * finds the gap immediately: half the register has nothing to be filed against.
             *
             * The two exchange accounts (4400, 4450) are deliberately not here. They are posted by
             * CurrencyRevaluationService when a foreign balance is retranslated, and a hand-picked
             * "exchange gain" category would invite somebody to book one by hand alongside the automatic
             * entry — which is how an account comes to be counted twice.
             */
            ['code' => 'service-revenue', 'name' => 'Service Revenue', 'account_code' => '4100', 'description' => 'Fees billed for work done'],
            ['code' => 'sales-revenue', 'name' => 'Sales Revenue', 'account_code' => '4200', 'description' => 'Product sales'],
            ['code' => 'other-income', 'name' => 'Other Income', 'account_code' => '4300', 'description' => 'Receipts with no more specific category'],
        ];

        foreach ($types as $type) {
            $account = $type['account_code']
                ? Account::where('code', $type['account_code'])->first()
                : null;

            TransactionType::updateOrCreate(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'account_id' => $account?->id,
                    'description' => $type['description'],
                ]
            );
        }

        $this->command?->info('Seeded '.count($types).' transaction types.');
    }
}
