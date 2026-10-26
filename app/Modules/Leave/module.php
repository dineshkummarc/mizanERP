<?php

/**
 * What this module is, and what it owns.
 *
 * Requires Employees and nothing else. Payroll is deliberately NOT declared:
 * leave is worth having for the register, the balances and the approvals on
 * their own, and the company running this in production docks nothing for
 * unpaid absence today. The payroll join — pro-rating pay on lop_days — is a
 * later phase, guarded at its call site rather than declared here, so leave
 * stays sellable to a company that runs no payroll at all.
 * See docs/hrms-plan.md §2 and §5.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'leave',
    'label' => 'Leave',
    'description' => 'Leave types, entitlements, requests, per-day records and computed balances.',
    'requires' => [
        'employees',
    ],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Leave\LeavePlugin::class,

    'models' => [
        'App\\Models\\LeaveType' => \App\Modules\Leave\Models\LeaveType::class,
        'App\\Models\\LeaveEntitlement' => \App\Modules\Leave\Models\LeaveEntitlement::class,
        'App\\Models\\LeaveApproval' => \App\Modules\Leave\Models\LeaveApproval::class,
        'App\\Models\\LeaveAdjustment' => \App\Modules\Leave\Models\LeaveAdjustment::class,
        'App\\Models\\LeaveRequest' => \App\Modules\Leave\Models\LeaveRequest::class,
        'App\\Models\\LeaveDay' => \App\Modules\Leave\Models\LeaveDay::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\LeaveRequests\\LeaveRequestResource' => \App\Modules\Leave\Filament\Resources\LeaveRequests\LeaveRequestResource::class,
        'App\\Filament\\Resources\\LeaveTypes\\LeaveTypeResource' => \App\Modules\Leave\Filament\Resources\LeaveTypes\LeaveTypeResource::class,
        'App\\Filament\\Resources\\LeaveEntitlements\\LeaveEntitlementResource' => \App\Modules\Leave\Filament\Resources\LeaveEntitlements\LeaveEntitlementResource::class,
    ],

    'widgets' => [
        'App\\Filament\\Widgets\\LeaveAwaitingDecisionOverview' => \App\Modules\Leave\Filament\Widgets\LeaveAwaitingDecisionOverview::class,
    ],

    // What the report builder may report on — reports-expansion-plan.md Phase 6, item 1. A dataset is
    // declared by the module that owns the subject, so it arrives with a module to gate on.
    'datasets' => [
        'App\\Reporting\\LeaveDayDataset' => \App\Modules\Leave\Reporting\LeaveDayDataset::class,
    ],

    /**
     * Where this module's dropdowns are edited — App\Support\OptionLists.
     *
     * A leave type is a row carrying day counts, accrual and notice, not a word, so it keeps its own screen.
     * Policy that applies to all of them — when the leave year starts, whether days carry — is in Company
     * Settings instead.
     */
    'option_lists' => [
        'leave.leave_type' => [
            'label' => 'Leave types',
            'help' => 'The kinds of leave your company grants, each with its own days, accrual and notice period.',
            'managed_by' => 'App\\Filament\\Resources\\LeaveTypes\\LeaveTypeResource',
        ],
    ],

    'permission_groups' => [
        'LeaveRequest',
        'LeaveType',
        'LeaveEntitlement',
    ],

    'permissions' => [
        // Leave. Approving is its own permission, exactly as it is for expense
        // claims: filing leave is not deciding it, and the Employee role gets
        // the first four and never the fifth.
        ['name' => 'LeaveRequestView', 'group' => 'LeaveRequest'],
        ['name' => 'LeaveRequestCreate', 'group' => 'LeaveRequest'],
        ['name' => 'LeaveRequestUpdate', 'group' => 'LeaveRequest'],
        ['name' => 'LeaveRequestDelete', 'group' => 'LeaveRequest'],
        ['name' => 'LeaveRequestApprove', 'group' => 'LeaveRequest'],
        // Leave types are reference data: HR edits the day counts, an employee
        // reads them so the form can say what they are asking for.
        ['name' => 'LeaveTypeView', 'group' => 'LeaveType'],
        ['name' => 'LeaveTypeCreate', 'group' => 'LeaveType'],
        ['name' => 'LeaveTypeUpdate', 'group' => 'LeaveType'],
        ['name' => 'LeaveTypeDelete', 'group' => 'LeaveType'],
        // Entitlements and their adjustment rows. LeaveEntitlementUpdate is what
        // grants an adjustment — the row that moves somebody's balance — so it is
        // deliberately not in the Employee role.
        ['name' => 'LeaveEntitlementView', 'group' => 'LeaveEntitlement'],
        ['name' => 'LeaveEntitlementCreate', 'group' => 'LeaveEntitlement'],
        ['name' => 'LeaveEntitlementUpdate', 'group' => 'LeaveEntitlement'],
        ['name' => 'LeaveEntitlementDelete', 'group' => 'LeaveEntitlement'],
    ],

    /**
     * Which of this module's permissions each role starts with.
     *
     * Administrator holds everything and is not listed. Manager and CEO are *additions* to the
     * role below them — RoleSeeder composes Accountant -> Manager -> CEO — so a permission
     * already granted to Accountant is not repeated here.
     */
    'role_grants' => [
        // Every member of staff.
        'Employee' => [
            'LeaveEntitlementView',
            'LeaveRequestCreate',
            'LeaveRequestUpdate',
            'LeaveRequestView',
            'LeaveTypeView',
        ],
    ],

    /**
     * Which domain of the two-level shell this module's screens appear in.
     *
     * Keyed on the navigation group label the resources and pages declare. Labels are shared —
     * "Employee" is claimed by ten modules — so agreement is normal and a label claimed for two
     * different domains throws in ModuleManifest rather than resolving to whichever manifest was
     * read last. The six domains themselves are App\Support\NavigationDomains.
     */
    'navigation' => [
        'Employee' => 'people',
    ],
];
