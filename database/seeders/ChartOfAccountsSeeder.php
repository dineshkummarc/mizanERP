<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Seeder;

/**
 * The chart of accounts a business starts with.
 *
 * ADDITIVE, and firstOrCreate rather than updateOrCreate is the whole of it: this
 * runs at provisioning, again from tenants:seed-baseline whenever a release adds
 * an account, and again from any db:seed. On every run after the first, the rows
 * it matches are a live chart somebody has been posting to — so an account that
 * already exists is left exactly as it is, name, type, parent and all.
 *
 * updateOrCreate made each of those runs rewrite the chart to whatever this file
 * happened to say. A company that renamed 4200 to "Consulting Revenue" got it
 * silently renamed back with seven journal entries hanging off it, and reparenting
 * an account that had been posted to threw out of Account::booted() — correctly,
 * because giving a posted-to account children retires it for all future postings.
 *
 * The consequence to accept: correcting a name or a parent here does not reach
 * companies that already have that account. That is the right way round. Reference
 * data a company cannot edit (currencies, banks, tax schedules) may be re-asserted;
 * a chart of accounts is the company's own and is not ours to rewrite.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run()
    {
        // Payroll-oriented default chart. Parents are group headers
        // (no manual entry); children are the postable leaf accounts.
        $chart = [
            ['code' => '1000', 'name' => 'Assets', 'type' => 'asset', 'allow_manual_entry' => false, 'children' => [
                ['code' => '1100', 'name' => 'Cash / Bank', 'type' => 'asset'],
                ['code' => '1150', 'name' => 'Petty Cash', 'type' => 'asset', 'description' => 'Imprest petty cash box'],
                ['code' => '1200', 'name' => 'Employee Advances', 'type' => 'asset', 'description' => 'Advances paid to employees, recovered via payroll'],
                ['code' => '1250', 'name' => 'Accounts Receivable', 'type' => 'asset', 'description' => 'Amounts owed by customers on issued invoices'],
                // The customer's side of §153. A corporate customer pays an invoice short and hands over a
                // tax deduction certificate for the rest; that rest is not a loss and not a discount — it is
                // income tax paid on the company's behalf, and an asset until the company's own return
                // absorbs it. InvoiceService::recordPayment() debits it; the Tax Withheld by Customers
                // report reads it back for the return.
                ['code' => '1260', 'name' => 'Advance Income Tax', 'type' => 'asset', 'description' => 'Income tax withheld by customers on receipts, adjustable against the company\'s own liability'],
                ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'description' => 'Stock on hand at cost'],
                // Deferrals — docs/erpnext-gap-plan.md Phase 5. A cost paid in advance is not an expense
                // yet: an annual licence bought in July is eleven months of next year's costs sitting in
                // this month's profit unless it is held here and charged monthly. See DeferralService.
                ['code' => '1350', 'name' => 'Prepaid Expenses', 'type' => 'asset', 'description' => 'Paid in advance and not yet incurred; charged a month at a time'],
                ['code' => '1400', 'name' => 'Office Equipment', 'type' => 'asset', 'description' => 'Fixed assets: computers, furniture, hardware'],
                ['code' => '1450', 'name' => 'Vehicles', 'type' => 'asset', 'description' => 'Fixed assets: company vehicles'],
                ['code' => '1500', 'name' => 'Accumulated Depreciation', 'type' => 'asset', 'normal_balance' => 'credit', 'description' => 'Contra-asset: credit-normal'],
            ]],
            ['code' => '2000', 'name' => 'Liabilities', 'type' => 'liability', 'allow_manual_entry' => false, 'children' => [
                ['code' => '2100', 'name' => 'Income Tax Payable', 'type' => 'liability', 'description' => 'Withholding tax deducted from salaries, payable to FBR'],
                ['code' => '2150', 'name' => 'Sales Tax Payable', 'type' => 'liability', 'description' => 'Sales tax on invoices (output less input)'],
                ['code' => '2200', 'name' => 'ESI / Health Insurance Payable', 'type' => 'liability'],
                // Statutory contributions, one account each. Deliberately NOT folded
                // into 2200: each scheme files its own monthly return, and a single
                // account holding three of them cannot be reconciled against any of
                // them. See config/statutory.php.
                ['code' => '2210', 'name' => 'EOBI Payable', 'type' => 'liability', 'description' => 'Employee and employer EOBI contributions, payable monthly'],
                ['code' => '2220', 'name' => 'Social Security Payable', 'type' => 'liability', 'description' => 'Provincial social security employer contribution (SESSI/PESSI and equivalents)'],
                ['code' => '2230', 'name' => 'Provident Fund Payable', 'type' => 'liability', 'description' => 'Employee contribution and employer match held for the fund'],
                ['code' => '2300', 'name' => 'Salaries Payable', 'type' => 'liability', 'description' => 'Net salaries owed to employees'],
                // What approved expense claims owe staff until a payslip reimburses them — docs/bexio-gap-plan.md
                // Phase 3. Credited by ExpenseClaimPosting at approval, debited by the payslip's reimbursement line.
                ['code' => '2310', 'name' => 'Expense Reimbursements Payable', 'type' => 'liability', 'description' => 'Approved expense claims not yet reimbursed to employees'],
                ['code' => '2400', 'name' => 'Accounts Payable', 'type' => 'liability', 'description' => 'Amounts owed to suppliers on bills'],
                // The other half of the deferral pair. Billed is not earned: an annual subscription invoiced
                // in July is a liability to deliver eleven more months of it, and recognising the whole
                // amount on the day of the invoice overstates this year's profit by that much.
                ['code' => '2500', 'name' => 'Deferred Revenue', 'type' => 'liability', 'description' => 'Billed and not yet earned; recognised a month at a time'],
                // Money in the bank that is not yet anybody's revenue and settles no invoice: a deposit on a
                // fixed-price project, a retainer, the remainder of an over-payment. A liability because the
                // company owes the customer the work or the money back — docs/erpnext-gap-plan.md §2.2.
                ['code' => '2600', 'name' => 'Customer Advances', 'type' => 'liability', 'description' => 'Received from customers against no invoice; applied to invoices as they are raised'],
            ]],
            ['code' => '3000', 'name' => 'Equity', 'type' => 'equity', 'allow_manual_entry' => false, 'children' => [
                ['code' => '3100', 'name' => 'Owner Equity', 'type' => 'equity'],
                ['code' => '3200', 'name' => 'Retained Earnings', 'type' => 'equity'],
                // The counter-account for opening balances, so bringing a book
                // onto the system is distinguishable from genuine owner
                // contributions. Once every account's opening balance is
                // entered, this should net to zero.
                ['code' => '3300', 'name' => 'Opening Balance Equity', 'type' => 'equity', 'description' => 'Counter-account for account opening balances; nets to zero once the book is fully opened'],
            ]],
            ['code' => '4000', 'name' => 'Income', 'type' => 'income', 'allow_manual_entry' => false, 'children' => [
                ['code' => '4100', 'name' => 'Service Revenue', 'type' => 'income'],
                ['code' => '4200', 'name' => 'Sales Revenue', 'type' => 'income', 'description' => 'Product sales'],
                ['code' => '4300', 'name' => 'Other Income', 'type' => 'income', 'description' => 'Non-product invoice lines'],
                // Exchange differences, one account each rather than a gain account and
                // a loss account: a gain of 100 that becomes a loss of 40 should read as
                // a net 40 loss, not as a gain of 100 beside a loss of 140. A debit
                // balance shows as a negative income line, which is what a loss is.
                ['code' => '4400', 'name' => 'Unrealised Exchange Gain / (Loss)', 'type' => 'income', 'description' => 'Foreign balances retranslated at period end; no money has moved'],
                ['code' => '4450', 'name' => 'Realised Exchange Gain / (Loss)', 'type' => 'income', 'description' => 'The difference between what a foreign amount was booked at and what was actually settled'],
            ]],
            ['code' => '5000', 'name' => 'Expenses', 'type' => 'expense', 'allow_manual_entry' => false, 'children' => [
                ['code' => '5050', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'description' => 'Inventory cost of product sales'],
                ['code' => '5100', 'name' => 'Basic Salary Expense', 'type' => 'expense'],
                ['code' => '5200', 'name' => 'Medical Allowance Expense', 'type' => 'expense'],
                ['code' => '5300', 'name' => 'Petrol Allowance Expense', 'type' => 'expense'],
                ['code' => '5400', 'name' => 'Device Allowance Expense', 'type' => 'expense'],
                ['code' => '5500', 'name' => 'Bonus & Overtime Expense', 'type' => 'expense'],
                ['code' => '5600', 'name' => 'Meal Expense', 'type' => 'expense'],
                ['code' => '5700', 'name' => 'Rent Expense', 'type' => 'expense'],
                ['code' => '5750', 'name' => 'Utilities Expense', 'type' => 'expense'],
                ['code' => '5800', 'name' => 'Fuel & Travel Expense', 'type' => 'expense'],
                ['code' => '5850', 'name' => 'Office Supplies Expense', 'type' => 'expense'],
                ['code' => '5860', 'name' => 'Cleaning Expense', 'type' => 'expense'],
                ['code' => '5900', 'name' => 'Miscellaneous Expense', 'type' => 'expense', 'description' => 'Costs with no more specific account'],
                ['code' => '5990', 'name' => 'Depreciation Expense', 'type' => 'expense'],
                ['code' => '5995', 'name' => 'Loss on Asset Disposal', 'type' => 'expense'],
            ]],
        ];

        foreach ($chart as $parentData) {
            $children = $parentData['children'];
            unset($parentData['children']);

            $parent = Account::firstOrCreate(['code' => $parentData['code']], $parentData);

            foreach ($children as $childData) {
                Account::firstOrCreate(
                    ['code' => $childData['code']],
                    $childData + ['parent_id' => $parent->id]
                );
            }
        }
    }
}
