<?php

return [

    /*
    | When true, payroll journal entries are auto-approved and posted on
    | creation. When false they are created as pending_approval and a
    | Manager/CEO must approve and post them.
    */
    'auto_post_payroll' => env('ACCOUNTING_AUTO_POST_PAYROLL', false),

    /*
    | When true, a journal entry must be approved by somebody other than the
    | person who wrote it. True is the right default and the one every
    | accounting control here assumes.
    |
    | It also assumes a second person exists. A company run by one operator has
    | nobody to route an entry to, so the rule becomes a dead end rather than a
    | control — the entry sits at pending_approval forever while the money has
    | already moved. Such a company turns this off, and the audit trail records
    | each self-approval as one. See SecondApproverRule.
    */
    'require_second_approver' => env('ACCOUNTING_REQUIRE_SECOND_APPROVER', true),

    /*
    | Account codes used by payroll posting (must exist in the chart of
    | accounts — see ChartOfAccountsSeeder).
    */
    'payroll_accounts' => [
        'basic_wage' => '5100',
        'medical_allowance' => '5200',
        'petrol_allowance' => '5300',
        'device_allowance' => '5400',
        'bonus_overtime' => '5500',
        // A liability, not an expense, since docs/bexio-gap-plan.md Phase 3: the claim books its own
        // expense when approved and credits this; the payslip's reimbursement line clears it.
        'expense_reimbursement' => '2310',
        'meal_recovery' => '5600',
        'tax_payable' => '2100',
        'esi_payable' => '2200',
        // Phase 9. One account per scheme, because each files its own monthly return and
        // a shared account cannot be reconciled against any of them.
        'eobi_payable' => '2210',
        'social_security_payable' => '2220',
        'provident_fund_payable' => '2230',
        'salaries_payable' => '2300',
        'employee_advances' => '1200',
    ],

    /*
     * The construction account mapping — docs/construction-management-plan.md §18.2, resolved by
     * App\Modules\Accounting\Support\ConstructionAccounts and seeded by ConstructionAccountsSeeder.
     *
     * Defaults rather than constants: a company that keeps retention in 1625 changes one setting instead of
     * editing a service. The keys are the semantics, and the list of them lives on that class.
     *
     * **Retention receivable is an asset**, which is the whole of §10.4: a certificate invoices the work gross and
     * shows retention as its own line here. Invoicing net understates revenue for the life of the job and then
     * makes the release look like revenue earned in a period when no work happened.
     */
    'construction_accounts' => [
        'contract_revenue' => '4600',
        'contract_assets' => '1610',
        'contract_liabilities' => '2630',
        'retention_receivable' => '1620',
        'retention_payable' => '2620',
        'subcontract_advance' => '1630',
        'materials_on_site' => '1600',
        'goods_received_not_invoiced' => '2600',
        'accrued_subcontract_costs' => '2610',
        'foreseeable_losses' => '2640',
        'job_cost_labour' => '5620',
        'job_cost_material' => '5630',
        'job_cost_plant' => '5640',
        'job_cost_subcontract' => '5650',
        'job_cost_other' => '5660',
        // Credit-normal expense accounts (§7.3): an internal charge is a recovery against the department that
        // owns the plant, not turnover.
        'plant_hire_recovery' => '5670',
        'burden_absorbed' => '5680',
        'absorption_variance' => '5690',
    ],
];
