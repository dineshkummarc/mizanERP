<?php

namespace App\Modules\Payroll;

use App\Events\RaisingMonthlyPayments;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Employees\Filament\Resources\Employees\EmployeeResource;
use App\Modules\Employees\Filament\Resources\EmployeeSettings\EmployeeSettingResource;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Console\Commands\CheckPayrollAccounts;
use App\Modules\Payroll\Console\Commands\OpenPayrollMonth;
use App\Modules\Payroll\Console\Commands\PostPendingPayrollEntries;
use App\Modules\Payroll\Console\Commands\SetPayrollAutoPosting;
use App\Modules\Payroll\Console\Commands\VerifyPayComponents;
use App\Modules\Payroll\Events\PayslipReviewed;
use App\Modules\Payroll\Filament\Pages\EobiReturnFile;
use App\Modules\Payroll\Filament\Pages\FbrTaxFile;
use App\Modules\Payroll\Filament\Pages\PayrollRegister;
use App\Modules\Payroll\Filament\Pages\SalaryBankFile;
use App\Modules\Payroll\Filament\Pages\TaxSummary;
use App\Modules\Payroll\Filament\RelationManagers\EmployeeSettingComponentsRelationManager;
use App\Modules\Payroll\Filament\Support\WithholdingCertificatesBulkAction;
use App\Modules\Payroll\Listeners\CopyReviewOntoPayment;
use App\Modules\Payroll\Listeners\RaiseSalaryPayments;
use App\Modules\Payroll\Models\AnnualTax;
use App\Modules\Payroll\Models\EmployeeSettingComponent;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\SalarySlab;
use App\Modules\Payroll\Policies\AnnualTaxPolicy;
use App\Modules\Payroll\Policies\PayComponentPolicy;
use App\Modules\Payroll\Policies\PayrollRunPolicy;
use App\Modules\Payroll\Policies\PayslipPolicy;
use App\Modules\Payroll\Policies\SalarySlabPolicy;
use App\Modules\Payroll\Support\PayrollReports;
use App\Support\LedgerDimensions;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportRenderers;
use App\Support\ResourceContributions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the Payroll module owns that Filament does not discover.
 *
 * Policies are registered EXPLICITLY. Laravel guesses App\Models\X ->
 * App\Policies\XPolicy, which cannot resolve a model living in a module
 * directory, and Filament treats a model with no policy as allowed — so without
 * this map every resource here would be open to any authenticated user.
 * ModuleCoverageTest fails the build if one is missing.
 */
class PayrollServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        AnnualTax::class => AnnualTaxPolicy::class,
        PayComponent::class => PayComponentPolicy::class,
        PayrollRun::class => PayrollRunPolicy::class,
        Payslip::class => PayslipPolicy::class,
        SalarySlab::class => SalarySlabPolicy::class,
    ];

    public function register(): void
    {
        // Payroll is what makes a month settled, so it answers the question.
        // Attendance asks the contract and no longer imports PayrollRun.
        $this->app->bind(
            \App\Support\Contracts\PeriodLock::class,
            \App\Modules\Payroll\Support\PayrollRunPeriodLock::class,
        );

        // What an hour of somebody's time cost, for the project margin report. Timesheets asks the
        // contract; the answer is the payslip that paid for the hour — see PayslipLabourCost.
        $this->app->bind(
            \App\Support\Contracts\LabourCost::class,
            \App\Modules\Payroll\Support\PayslipLabourCost::class,
        );
    }

    public function boot(): void
    {
        // This module's reports in the Reports hub. Registered rather than listed in Core, which
        // used to name all eighteen — see App\Support\Reporting\ReportCatalogue.
        /*
         * The month's register — reports-expansion-plan.md Phase 2.1.
         *
         * Filed under *People & payroll* rather than *Payroll & tax*: that section is the three filing
         * outputs, and this is a management report about a month. It sits beside the timesheet pair, which
         * is what somebody comparing hours against pay would want.
         */
        ReportCatalogue::register(
            'People & payroll',
            PayrollRegister::class,
            'Every employee by every part of their pay for a month, tied to the payroll journal.',
        );

        ReportCatalogue::register('Payroll & tax', TaxSummary::class, 'Tax withheld per employee for the year, with the slab it fell in.');
        ReportCatalogue::register('Payroll & tax', FbrTaxFile::class, 'The withholding statement, in the format FBR accepts.');
        ReportCatalogue::register('Payroll & tax', SalaryBankFile::class, 'Salary payments as a bank upload file, for a payroll month.');
        // Written and wired to nothing until docs/bexio-gap-plan.md Phase 5, item 17.
        ReportCatalogue::register('Payroll & tax', EobiReturnFile::class, 'The monthly EOBI return, as the payslips carry it, for the portal.');

        // Tax deduction certificates for every selected employee at once — Phase 5, item 18. Offered on
        // the Employees list from here, because the certificate is Payroll's and Employees names nothing.
        ResourceContributions::addBulkAction(
            EmployeeResource::class,
            'withholdingCertificates',
            fn () => WithholdingCertificatesBulkAction::make(),
        );

        // A payslip's review decision is copied onto the salary payment waiting on it. Registered here
        // rather than in a global EventServiceProvider because the pair is Payroll's business — see
        // App\Modules\Payroll\Listeners\CopyReviewOntoPayment.
        Event::listen(PayslipReviewed::class, CopyReviewOntoPayment::class);

        // The month's salary payables, raised when either bank-file page is opened. Listened for rather
        // than called by name, because the caller is in Accounting and naming this from there was the last
        // `accounting -> payroll` edge. See App\Events\RaisingMonthlyPayments.
        Event::listen(RaisingMonthlyPayments::class, RaiseSalaryPayments::class);

        // A payment's link to the payslip it pays, contributed rather than declared — Accounting keeps the
        // `payslip_id` column and stops naming a Payslip. Same mechanism as the Projects tab in phase 7.
        // The foreign key is named, and it has to be: `belongsTo()` infers the key from the *calling
        // method's* name, and inside a closure that name is `{closure}` — so leaving it out produced a
        // query for `payments.app\_modules\_payroll\{closure}_id` and thirteen failing tests. Phase 7's
        // relations escaped this only because they passed their keys anyway.
        Payment::resolveRelationUsing(
            'payslip',
            fn (Payment $payment) => $payment->belongsTo(Payslip::class, 'payslip_id'),
        );

        $this->contributeToEmployees();

        // Payroll's own three reports in the Reports explorer. Registered here rather than rendered by
        // Accounting, which used to import WithholdingTaxSummary, SalaryBankExportService and Payslip to
        // do it — see docs/module-packaging-plan.md §8 Group A.
        ReportRenderers::register('TaxSummary', fn (string $asOf, bool $comparison, array $asked): array => app(PayrollReports::class)
            ->taxSummary($asOf, $asked['month'] ?? null));
        ReportRenderers::register('PayrollRegister', fn (string $asOf): array => app(PayrollReports::class)->register($asOf));

        /*
         * What a payslip's postings were for — `docs/erpnext-gap-plan.md` Phase 1.
         *
         * Payroll is the one path that has always stamped `source_type`, so this registration makes a
         * profit and loss by department readable over history that already exists rather than only over
         * postings made from today. `employees.department` is free text and that is deliberate: the gap
         * plan refuses a `cost_centers` table until somebody needs what a table buys.
         */
        LedgerDimensions::register(Payslip::class, fn (Payslip $payslip): array => [
            LedgerDimensions::PARTY => $payslip->employee?->name,
            LedgerDimensions::DEPARTMENT => $payslip->employee?->department,
        ], ['employee']);
        ReportRenderers::register('FbrTaxFile', fn (string $asOf): array => app(PayrollReports::class)->taxFile($asOf));
        ReportRenderers::register('SalaryBankFile', fn (string $asOf): array => app(PayrollReports::class)->salaryFile($asOf));
        ReportRenderers::register('EobiReturnFile', fn (string $asOf): array => app(PayrollReports::class)->eobiReturn($asOf));

        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/routes/console.php');

        // Laravel only auto-discovers commands in app/Console/Commands,
        // so a moved command has to be registered here or it disappears
        // from artisan — and from the scheduler, silently.
        $this->commands([
            CheckPayrollAccounts::class,
            OpenPayrollMonth::class,
            PostPendingPayrollEntries::class,
            SetPayrollAutoPosting::class,
            VerifyPayComponents::class,
        ]);
    }

    /**
     * What this module adds to the Employees screens, registered here rather than declared there.
     *
     * `employees -> payroll` was the linchpin of the seven-module cycle left after phase 9, and it was two
     * references: `EmployeeSetting::components()`, a hasMany onto a model this module ships, and the
     * added-components relation manager, which offered `PayComponent`. Payroll **requires** Employees, so
     * both were cycles — and both were written with fully-qualified class names precisely so the import would
     * not show, which phase 0's scan sees through and which the plan names for what it is.
     *
     * Deleting it freed four other modules at once: accounting, attendance and leave were each held in only
     * by a three-cycle running back through here. Same shape as Core in phase 9.
     *
     * Registered unconditionally, like every provider in this application — one deployment serves every
     * company and the licence check belongs on the resource. What this guarantees is the useful property: if
     * the Payroll **package** is absent, neither the relation nor the tab exists, which is correct, because
     * the rows they read live in tables this module ships.
     *
     * The foreign key is named explicitly. `hasMany()` infers it from the *calling method's* name, and inside
     * a closure that name is `{closure}` — the phase 8C failure, recorded so it is not rediscovered.
     */
    private function contributeToEmployees(): void
    {
        EmployeeSetting::resolveRelationUsing(
            'components',
            fn (EmployeeSetting $setting) => $setting->hasMany(EmployeeSettingComponent::class, 'employee_setting_id'),
        );

        ResourceContributions::addRelationManager(
            EmployeeSettingResource::class,
            EmployeeSettingComponentsRelationManager::class,
        );
    }
}
