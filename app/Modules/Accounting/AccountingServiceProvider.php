<?php

namespace App\Modules\Accounting;

use App\Modules\Accounting\Console\Commands\BackfillEntrySources;
use App\Modules\Accounting\Console\Commands\BackfillPaymentEntriesCommand;
use App\Modules\Accounting\Console\Commands\RaiseScheduledTransactions;
use App\Modules\Accounting\Console\Commands\RaiseSubscriptionPayments;
use App\Modules\Accounting\Console\Commands\RebuildAssetDepreciationCommand;
use App\Modules\Accounting\Filament\Pages\AccountRegister;
use App\Modules\Accounting\Filament\Pages\BalanceSheet;
use App\Modules\Accounting\Filament\Pages\BankPaymentFile;
use App\Modules\Accounting\Filament\Pages\BankReconciliationStatement;
use App\Modules\Accounting\Filament\Pages\BudgetVsActual;
use App\Modules\Accounting\Filament\Pages\CashCommitments as CashCommitmentsPage;
use App\Modules\Accounting\Filament\Pages\CashFlow;
use App\Modules\Accounting\Filament\Pages\ContractorPayments;
use App\Modules\Accounting\Filament\Pages\CurrencyRevaluation;
use App\Modules\Accounting\Filament\Pages\FindTransactions;
use App\Modules\Accounting\Filament\Pages\FixedAssetRegister;
use App\Modules\Accounting\Filament\Pages\GeneralLedger;
use App\Modules\Accounting\Filament\Pages\LoansOutstanding;
use App\Modules\Accounting\Filament\Pages\PettyCashBook;
use App\Modules\Accounting\Filament\Pages\ProfitAndLoss;
use App\Modules\Accounting\Filament\Pages\ProfitAndLossByDimension;
use App\Modules\Accounting\Filament\Pages\TrialBalance;
use App\Modules\Accounting\Filament\Pages\WithholdingStatement;
use App\Modules\Accounting\Filament\Settings\CurrencySettingsSection;
use App\Modules\Accounting\Filament\Settings\LedgerFreezeSettingsSection;
use App\Modules\Accounting\Filament\Settings\PayrollPostingSettingsSection;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankStatement;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\BeneficiarySubscription;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLine;
use App\Modules\Accounting\Models\CompanyBankAccount;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Models\Loan;
use App\Modules\Accounting\Models\LoanInstalment;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\PettyCashVoucher;
use App\Modules\Accounting\Models\ScheduledTransaction;
use App\Modules\Accounting\Models\ScheduledTransactionLine;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Policies\AccountPolicy;
use App\Modules\Accounting\Policies\BankPolicy;
use App\Modules\Accounting\Policies\BankStatementLinePolicy;
use App\Modules\Accounting\Policies\BankStatementPolicy;
use App\Modules\Accounting\Policies\BeneficiaryPolicy;
use App\Modules\Accounting\Policies\BeneficiarySubscriptionPolicy;
use App\Modules\Accounting\Policies\BudgetLinePolicy;
use App\Modules\Accounting\Policies\BudgetPolicy;
use App\Modules\Accounting\Policies\CompanyBankAccountPolicy;
use App\Modules\Accounting\Policies\CurrencyPolicy;
use App\Modules\Accounting\Policies\FixedAssetPolicy;
use App\Modules\Accounting\Policies\JournalEntryLinePolicy;
use App\Modules\Accounting\Policies\JournalEntryPolicy;
use App\Modules\Accounting\Policies\LoanInstalmentPolicy;
use App\Modules\Accounting\Policies\LoanPolicy;
use App\Modules\Accounting\Policies\PaymentPolicy;
use App\Modules\Accounting\Policies\ScheduledTransactionLinePolicy;
use App\Modules\Accounting\Policies\ScheduledTransactionPolicy;
use App\Modules\Accounting\Policies\TransactionTypePolicy;
use App\Modules\Accounting\Services\FiscalYearClosingService;
use App\Modules\Accounting\Support\BankReconciliationReports;
use App\Modules\Accounting\Support\CashCommitmentReports;
use App\Modules\Accounting\Support\DimensionReports;
use App\Modules\Accounting\Support\FixedAssetReports;
use App\Modules\Accounting\Support\LoanReports;
use App\Modules\Accounting\Support\OpeningBalanceCsvImporter;
use App\Modules\Accounting\Support\ReportPane;
use App\Modules\Accounting\Support\ReturnPackReports;
use App\Modules\Accounting\Support\WithholdingReports;
use App\Modules\Core\Models\Bank;
use App\Modules\Employees\Models\Employee;
use App\Support\Contracts\FiscalYearCloseCheck;
use App\Support\CsvImporters;
use App\Support\CustomFieldSubjects;
use App\Support\DashboardStats;
use App\Support\JournalEntryOwners;
use App\Support\LedgerDimensions;
use App\Support\ModuleMap;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use App\Support\SettingsSections;
use App\Support\TenantFileAccess;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the Accounting module owns that Filament does not discover.
 *
 * Policies are registered EXPLICITLY. Laravel guesses App\Models\X ->
 * App\Policies\XPolicy, which cannot resolve a model living in a module
 * directory, and Filament treats a model with no policy as allowed — so without
 * this map every resource here would be open to any authenticated user.
 * ModuleCoverageTest fails the build if one is missing.
 */
class AccountingServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Account::class => AccountPolicy::class,
        Bank::class => BankPolicy::class,
        BankStatementLine::class => BankStatementLinePolicy::class,
        BankStatement::class => BankStatementPolicy::class,
        Beneficiary::class => BeneficiaryPolicy::class,
        Budget::class => BudgetPolicy::class,
        BudgetLine::class => BudgetLinePolicy::class,
        Currency::class => CurrencyPolicy::class,
        BeneficiarySubscription::class => BeneficiarySubscriptionPolicy::class,
        CompanyBankAccount::class => CompanyBankAccountPolicy::class,
        FixedAsset::class => FixedAssetPolicy::class,
        JournalEntryLine::class => JournalEntryLinePolicy::class,
        JournalEntry::class => JournalEntryPolicy::class,
        Loan::class => LoanPolicy::class,
        LoanInstalment::class => LoanInstalmentPolicy::class,
        Payment::class => PaymentPolicy::class,
        ScheduledTransaction::class => ScheduledTransactionPolicy::class,
        ScheduledTransactionLine::class => ScheduledTransactionLinePolicy::class,
        TransactionType::class => TransactionTypePolicy::class,
    ];

    public function boot(): void
    {
        // This module's reports in the Reports hub. Registered rather than listed in Core, which
        // used to name all eighteen — see App\Support\Reporting\ReportCatalogue.
        ReportCatalogue::register('Financial statements', BalanceSheet::class, 'What the company owns, owes and is worth, on a date.');
        ReportCatalogue::register('Financial statements', ProfitAndLoss::class, 'Income less expenses over a period, and the profit that leaves.');
        ReportCatalogue::register('Financial statements', CashFlow::class, 'Where the money actually came from and went, period by period.');
        ReportCatalogue::register(
            'Financial statements',
            ProfitAndLossByDimension::class,
            'The same profit, split by project or by department — and what could not be attributed.',
        );
        ReportCatalogue::register('Financial statements', TrialBalance::class, 'Every account with its balance, and the proof that the books add up.');
        // Beside the statements it derives from, not under Payroll & tax: that section is
        // deliberately payroll's three filing outputs and vanishes with the payroll module
        // (ReportsHubTest pins it), while this page is Accounting's own and must not keep
        // the section alive for a company that never bought payroll.
        ReportCatalogue::register('Financial statements', \App\Modules\Accounting\Filament\Pages\CorporateReturnPack::class, 'The year\'s corporate return figures: profit, tax adjustments, minimum tax, and the balance to pay.');
        // Drawn in the pane as the statement half of the page — ReturnPackReports says why it was missing.
        ReportRenderers::register('CorporateReturnPack', fn (string $asOf): array => app(ReturnPackReports::class)->corporate($asOf));
        ReportCatalogue::register('Financial statements', GeneralLedger::class, 'Every account, every entry against it, opening to closing — what an audit reads.');
        ReportCatalogue::register('Financial statements', BudgetVsActual::class, 'What was planned against what was spent, by account and by month.');
        ReportCatalogue::register('Receivables & payables', ContractorPayments::class, 'What each contractor has been paid, and over what period.');
        /*
         * The §165 statement — `docs/erpnext-gap-plan.md` Phase 4, item 4.
         *
         * Filed beside the contractor summary rather than under Financial statements: both answer a
         * question about money paid out to people who are not on the payroll, and somebody reaching for one
         * is usually reconciling the other.
         *
         * `asked['month']` comes from the pane's filter bar — §165 is filed monthly and read for the year,
         * so the month is a filter and the whole year is a legitimate answer. See `ReportPane::ASKS`.
         */
        ReportCatalogue::register(
            'Receivables & payables',
            WithholdingStatement::class,
            'Tax withheld from suppliers under §153: who, which section, and what was deducted.',
        );
        ReportRenderers::register(
            'WithholdingStatement',
            fn (string $asOf, bool|string $comparison, array $asked): array => app(WithholdingReports::class)
                ->statement($asOf, is_string($asked['month'] ?? null) ? $asked['month'] : null),
        );
        ReportCatalogue::register('Ledgers & books', AccountRegister::class, 'One account, every transaction against it, running balance — and edits.');
        ReportCatalogue::register('Ledgers & books', FindTransactions::class, 'Search the whole ledger by account, date, amount or wording.');
        ReportCatalogue::register('Ledgers & books', PettyCashBook::class, 'The cash float: what was spent, what is left, and replenishment.');
        ReportCatalogue::register('Ledgers & books', CurrencyRevaluation::class, 'Foreign balances at the rate on a date, and the difference posted.');
        ReportCatalogue::register('Bank files', BankPaymentFile::class, 'Selected payments as a bank transfer file.');

        /*
         * The loan book — reports-expansion-plan.md Phase 1.6.
         *
         * Registered with `ReportRenderers` rather than given an arm in `ReportPane`'s `match`, which is how
         * the eleven above are drawn. The newer path gives the page, the date and the module gate for free
         * and keeps the pane from growing a method per report; `ReportPane::for()` asks `ReportRenderers`
         * first, so both routes reach one closure and the pane and the page cannot disagree.
         */
        ReportCatalogue::register(
            'Ledgers & books',
            LoansOutstanding::class,
            'Every loan: what is left, the interest still to come, and whether the accounts agree.',
        );
        /*
         * Profit and loss by dimension — `docs/erpnext-gap-plan.md` Phase 1, item 4.
         *
         * `asked['dimension']` comes from the pane's own filter bar, which is why `ReportPane::ASKS` names
         * it: the picker, its options and the URL round-trip are machinery this report gets for free by
         * declaring what it needs.
         */
        ReportRenderers::register(
            'ProfitAndLossByDimension',
            fn (string $asOf, bool|string $comparison, array $asked): array => app(DimensionReports::class)
                ->profitAndLoss($asOf, is_string($asked['dimension'] ?? null) ? $asked['dimension'] : LedgerDimensions::PROJECT),
        );

        ReportRenderers::register(
            'LoansOutstanding',
            fn (string $asOf): array => app(LoanReports::class)->outstanding($asOf),
        );

        /*
         * The forward cash view — Phase 1.7.
         *
         * Its sources come from `App\Support\CashCommitments`, which Accounting writes two of and Invoicing
         * the third. Registering them here rather than inside the report keeps the registry filled at boot,
         * so the report never has to ask whether a module got there first.
         */
        ReportCatalogue::register(
            'Ledgers & books',
            CashCommitmentsPage::class,
            'What is committed to leave or arrive over the next ninety days, and whether it is raised.',
        );
        CashCommitmentReports::registerSources();
        ReportRenderers::register(
            'CashCommitments',
            fn (string $asOf): array => app(CashCommitmentReports::class)->commitments($asOf),
        );

        /*
         * The asset register as a note to the accounts — Phase 2.5.
         *
         * Filed under *Ledgers & books* beside the loan book, which it is the mirror of: a register of things
         * the company holds against a register of what it owes, each tied to the accounts behind it.
         *
         * The plan costed this one at no new business logic, on the basis that the twelve-month charge came
         * from `DepreciationService`'s own method. It did not have one — every method it had posted entries —
         * so `DepreciationService::schedule()` was written for it. That is the only logic this report added.
         */
        ReportCatalogue::register(
            'Ledgers & books',
            FixedAssetRegister::class,
            'Every asset: cost, depreciation to date, what it is worth, and the year ahead.',
        );
        ReportRenderers::register(
            'FixedAssetRegister',
            fn (string $asOf): array => app(FixedAssetReports::class)->register($asOf),
        );

        /*
         * What the bank says against what the books say — Phase 2.6.
         *
         * The plan costed this as a report over completed statements. It cannot be: `complete()` requires the
         * statement balance to equal the ledger balance exactly, and an unpresented cheque makes those differ
         * by definition, so the statements with something to reconcile are precisely the ones that cannot be
         * closed. The report is therefore about open statements, and it is where the figure `complete()`
         * rejects is finally named.
         */
        ReportCatalogue::register(
            'Ledgers & books',
            BankReconciliationStatement::class,
            'The bank balance, the cheques it has not seen, and whether the books agree.',
        );
        ReportRenderers::register(
            'BankReconciliationStatement',
            fn (string $asOf): array => app(BankReconciliationReports::class)->statement($asOf),
        );

        // The records of this module that may carry custom fields. Registered by alias, which is what
        // `custom_fields.model_type` stores — see App\Support\CustomFieldSubjects.
        CustomFieldSubjects::register(ModuleMap::alias(Beneficiary::class), 'Beneficiaries');
        CustomFieldSubjects::register(ModuleMap::alias(FixedAsset::class), 'Fixed Assets');

        // A trial balance from the company's old system, at setup. Core reads the CSV; deciding what
        // balances, and posting the one entry it becomes, is accounting — see App\Support\CsvImporters.
        // Last, because it is the one that needs the chart of accounts to exist first.
        CsvImporters::register('opening_balances', OpeningBalanceCsvImporter::class, 30);

        // This module's blocks of the Company Settings screen, which Core used to write itself and so needed
        // `Currency`, `JournalEntryLine` and `Account` for — see App\Support\SettingsSections. Currency first
        // on that page: it is what every other figure there means.
        SettingsSections::register('accounting.currency', CurrencySettingsSection::class, 10);
        SettingsSections::register('accounting.payroll-posting', PayrollPostingSettingsSection::class, 60);
        // Closing off a period — `docs/erpnext-gap-plan.md` Phase 3. After the currency and before payroll
        // posting: it is a decision about the books rather than about a module's own behaviour.
        SettingsSections::register('accounting.ledger-freeze', LedgerFreezeSettingsSection::class, 20);

        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // Journal attachments, bank-statement imports and petty-cash receipts match the access of the
        // records they belong to — security audit finding 10.
        TenantFileAccess::registerRecordPrefix('journal-attachments/', fn (string $path) => JournalEntry::query()->where('attachment_path', $path)->first());
        TenantFileAccess::registerPermissionPrefix('statement-imports/', 'BankStatementView');
        TenantFileAccess::registerPermissionPrefix('petty-cash-receipts/', 'PettyCashView');

        // The documents Accounting itself books entries for. The register refuses to edit an entry any of
        // these owns, because the entry is the accounting half of that document and the two would
        // desynchronise silently. Invoicing and Inventory register their own — see
        // App\Support\JournalEntryOwners for why this is a registry rather than a list.
        // The real fiscal-year close, replacing the null default bound in ContractDefaultsServiceProvider.
        $this->app->bind(FiscalYearCloseCheck::class, FiscalYearClosingService::class);
        $this->app->bind(ReportPaneRenderer::class, ReportPane::class);

        JournalEntryOwners::register('a payment', Payment::class);
        JournalEntryOwners::register('a petty cash voucher', PettyCashVoucher::class);
        JournalEntryOwners::register('a fixed asset', FixedAsset::class);

        /*
         * What Accounting's own documents were for — `docs/erpnext-gap-plan.md` Phase 1.
         *
         * A payment knows who it was paid to, and where that is an employee it reaches their department —
         * the one dimension this application already stores, as free text on `employees.department`. The
         * gap plan is explicit that this is *not* a `cost_centers` table: a department table earns its
         * place when somebody needs a hierarchy, a code, or a rename that does not rewrite history.
         *
         * A petty cash voucher has a payee in prose and no party record, so it contributes nothing and
         * reports as unassigned. Stating that by registering nothing would read as an oversight; stating
         * it here is the difference between "we did not get to it" and "there is nothing there".
         */
        LedgerDimensions::register(Payment::class, function (Payment $payment): array {
            $payable = $payment->payable;

            return [
                LedgerDimensions::PARTY => $payable?->name,
                LedgerDimensions::DEPARTMENT => $payable instanceof Employee ? $payable->department : null,
            ];
        }, ['payable']);

        DashboardStats::register('accounting.pending-entries', fn () => auth()->user()?->can('JournalEntryApprove')
            ? Stat::make(
                'Journal Entries Awaiting Approval',
                JournalEntry::where('status', JournalEntry::STATUS_PENDING)->count(),
            )->description('pending')
            : null, sort: 20);

        $this->commands([
            BackfillEntrySources::class,
            BackfillPaymentEntriesCommand::class,
            RebuildAssetDepreciationCommand::class,
            RaiseScheduledTransactions::class,
            RaiseSubscriptionPayments::class,
        ]);

        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/routes/api.php');
    }
}
