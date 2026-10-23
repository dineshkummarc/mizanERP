<?php

namespace App\Modules\Invoicing;

use App\Modules\Accounting\Filament\Resources\BankStatementLines\BankStatementLineResource;
use App\Modules\Invoicing\Console\Commands\CheckContactDocumentExpiry;
use App\Modules\Invoicing\Console\Commands\RaiseRecurringInvoices;
use App\Modules\Invoicing\Console\Commands\SendCustomerStatements;
use App\Modules\Invoicing\Console\Commands\SendOverdueReminders;
use App\Modules\Invoicing\Fbr\FbrDriver;
use App\Modules\Invoicing\Fbr\NullFbrDriver;
use App\Modules\Invoicing\Filament\Pages\AgedPayables;
use App\Modules\Invoicing\Filament\Pages\AgedReceivables;
use App\Modules\Invoicing\Filament\Pages\CreditNotesIssued;
use App\Modules\Invoicing\Filament\Pages\FbrInvoiceReporting;
use App\Modules\Invoicing\Filament\Pages\RevenueByDimension;
use App\Modules\Invoicing\Filament\Pages\TaxWithheldByCustomers;
use App\Modules\Invoicing\Filament\Settings\CreditControlSettingsSection;
use App\Modules\Invoicing\Filament\Settings\DunningSettingsSection;
use App\Modules\Invoicing\Filament\Settings\StatementSettingsSection;
use App\Modules\Invoicing\Filament\Support\RecordReceiptFromStatementLine;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\ContactDocument;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceLine;
use App\Modules\Invoicing\Models\TaxRate;
use App\Modules\Invoicing\Policies\ContactDocumentPolicy;
use App\Modules\Invoicing\Policies\ContactPolicy;
use App\Modules\Invoicing\Policies\InvoiceLinePolicy;
use App\Modules\Invoicing\Policies\InvoicePolicy;
use App\Modules\Invoicing\Policies\TaxRatePolicy;
use App\Modules\Invoicing\Services\ControlReconciliation;
use App\Modules\Invoicing\Services\RecurringInvoiceService;
use App\Modules\Invoicing\Support\ContactCsvImporter;
use App\Modules\Invoicing\Support\CreditNoteReports;
use App\Modules\Invoicing\Support\InvoicingReports;
use App\Modules\Invoicing\Support\OpeningInvoiceCsvImporter;
use App\Modules\Invoicing\Support\RevenueReports;
use App\Support\CashCommitments;
use App\Support\CsvImporters;
use App\Support\CustomFieldSubjects;
use App\Support\DashboardStats;
use App\Support\JournalEntryOwners;
use App\Support\LedgerDimensions;
use App\Support\ModuleMap;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportRenderers;
use App\Support\ResourceContributions;
use App\Support\SettingsSections;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Everything the Invoicing module owns that Filament does not discover.
 *
 * Policies are registered EXPLICITLY. Laravel guesses App\Models\X ->
 * App\Policies\XPolicy, which cannot resolve a model living in a module
 * directory, and Filament treats a model with no policy as allowed — so without
 * this map every resource here would be open to any authenticated user.
 * ModuleCoverageTest fails the build if one is missing.
 */
class InvoicingServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Contact::class => ContactPolicy::class,
        ContactDocument::class => ContactDocumentPolicy::class,
        InvoiceLine::class => InvoiceLinePolicy::class,
        Invoice::class => InvoicePolicy::class,
        TaxRate::class => TaxRatePolicy::class,
    ];

    /**
     * Recurring invoices, as forward cash commitments — `docs/reports-expansion-plan.md` Phase 1.7.
     *
     * The report lives in Accounting and this is Invoicing's data, so it is *registered* rather than
     * imported: `docs/module-packaging-plan.md` §8 spent a phase removing `accounting -> invoicing`, and
     * one column of one report is not a reason to buy the edge back. See `App\Support\CashCommitments`,
     * which follows the same inversion — the caller asks and each module answers for itself.
     *
     * **Money coming in**, which is why the report has two totals. Every other source registered against
     * that registry is money leaving.
     */
    private function registerCashCommitments(): void
    {
        CashCommitments::register('recurring-invoice', function (string $from, string $to): array {
            $service = app(RecurringInvoiceService::class);
            $rows = [];

            // Illuminate's Carbon, not Carbon's own: `due()` type-hints the Laravel subclass and an
            // instance of the parent is not an instance of the child.
            // A recurring invoice is a monthly agreement and `due()` answers for one month, so the window
            // is walked a month at a time — at most four passes for a ninety-day horizon.
            $cursor = Carbon::parse($from)->startOfMonth();
            $end = Carbon::parse($to)->startOfMonth();

            while ($cursor->lessThanOrEqualTo($end)) {
                foreach ($service->due($cursor) as $agreement) {
                    $date = $agreement->invoiceDateFor($cursor);

                    if ($date->toDateString() < $from || $date->toDateString() > $to) {
                        continue;
                    }

                    $rows[] = [
                        'date' => $date->toDateString(),
                        'kind' => 'Recurring invoice',
                        'description' => trim(($agreement->contact?->name ?? 'Unknown customer')
                            .' · '.$agreement->description, ' ·'),
                        'amount' => $agreement->total(),
                        'direction' => 'in',
                        'raised' => $service->alreadyRaised($agreement, $cursor),
                    ];
                }

                $cursor = $cursor->addMonth();
            }

            return $rows;
        });
    }

    public function register(): void
    {
        // Resolved per-use rather than as a singleton, because the driver is a
        // per-company choice (`fbr.driver`, a TenantSettings override) and a
        // queue worker crosses companies. An unknown name throws rather than
        // quietly falling back to the null driver: "misconfigured" must never
        // read as "reporting", or a typo becomes a compliance breach that
        // looks exactly like a working system.
        $this->app->bind(FbrDriver::class, function (): FbrDriver {
            $name = (string) setting('fbr.driver', 'null');

            return match ($name) {
                NullFbrDriver::NAME => new NullFbrDriver,
                default => throw new InvalidArgumentException(
                    "Unknown FBR driver [{$name}] — a real integrator driver is phase 4 of "
                    .'docs/fbr-digital-invoicing-plan.md and does not exist yet.'
                ),
            };
        });
    }

    public function boot(): void
    {
        $this->registerCashCommitments();

        // This module's reports in the Reports hub. Registered rather than listed in Core, which
        // used to name all eighteen — see App\Support\Reporting\ReportCatalogue.
        ReportCatalogue::register('Receivables & payables', AgedReceivables::class, 'What customers owe, bucketed by how late it is.');
        ReportCatalogue::register('Receivables & payables', AgedPayables::class, 'What the company owes suppliers, bucketed by how late it is.');
        ReportCatalogue::register('Statutory reporting', FbrInvoiceReporting::class, 'Invoices FBR has not accepted, and issued invoices it never received.');

        /*
         * Revenue by customer, project and product — reports-expansion-plan.md Phase 3.4.
         *
         * Filed with the ageing reports: it is read by whoever is chasing or analysing what has been billed.
         */
        ReportCatalogue::register(
            'Receivables & payables',
            RevenueByDimension::class,
            'What was invoiced, to whom, on what project and for which product — net of credit notes.',
        );
        ReportRenderers::register(
            'RevenueByDimension',
            fn (string $asOf): array => app(RevenueReports::class)->byDimension($asOf),
        );

        /*
         * Credit notes issued — Phase 3.5.
         *
         * Filed under *Statutory reporting* rather than with the receivables: the report exists for the tax
         * question — whether a reversal was inside its window or covered by a Commissioner's approval — and
         * that section is where the FBR reports already live.
         */
        ReportCatalogue::register(
            'Statutory reporting',
            CreditNotesIssued::class,
            'Every credit note, what it reversed, and whether it was inside its window or approved.',
        );
        ReportRenderers::register(
            'CreditNotesIssued',
            fn (string $asOf): array => app(CreditNoteReports::class)->creditNotes($asOf),
        );

        // The records of this module that may carry custom fields. Registered by alias, which is what
        // `custom_fields.model_type` stores — see App\Support\CustomFieldSubjects.
        CustomFieldSubjects::register(ModuleMap::alias(Contact::class), 'Contacts');
        CustomFieldSubjects::register(ModuleMap::alias(Invoice::class), 'Invoices');

        // Clients and suppliers from a spreadsheet at setup. Core reads the CSV; what a row means is here,
        // because `Contact` is this module's — see App\Support\CsvImporters.
        // Sorted first, and so the type the page opens on: it is the one every company has a file of.
        CsvImporters::register('contacts', ContactCsvImporter::class, 10);

        /*
         * The invoices that were already open on the day the company arrived — Phase 5.
         *
         * Sorted after the opening trial balance (30) because that is the order to do it in: the balance
         * brings in the receivables *total* and these are the documents behind it, so importing them the
         * other way round leaves a stage where the two disagree for no reason. ERPNext's own guidance is the
         * same, and Phase 2's control check is what tells a company whether they now agree.
         *
         * 45 rather than 40, which construction's cost codes already hold: two importers on the same sort
         * would leave their order in the dropdown to whichever provider booted first.
         */
        CsvImporters::register('opening_invoices', OpeningInvoiceCsvImporter::class, 45);

        $this->commands([RaiseRecurringInvoices::class, SendOverdueReminders::class, SendCustomerStatements::class, CheckContactDocumentExpiry::class]);

        // An invoice's journal entry is the accounting half of the invoice, so the register must refuse
        // to edit it. Registered from here rather than named in Accounting: that naming was an
        // `accounting -> invoicing` edge, and Accounting does not require Invoicing.
        JournalEntryOwners::register('an invoice', Invoice::class);

        // "Record receipt" on a bank statement line — docs/bexio-gap-plan.md Phase 3, item 12. The line
        // is Accounting's and may not name an invoice, so the button is offered from here.
        ResourceContributions::addRecordAction(
            BankStatementLineResource::class,
            'recordReceipt',
            fn () => RecordReceiptFromStatementLine::make(),
        );

        // Receivables and payables, and what they are supposed to equal — `docs/erpnext-gap-plan.md`
        // Phase 2. Registered at boot so the health check finds the pair whether or not anybody has
        // opened a report, which is why `CashCommitmentReports::registerSources()` is called here too.
        ControlReconciliation::register();

        /*
         * Whether to chase overdue invoices, and when — `docs/erpnext-gap-plan.md` Phase 5.
         *
         * Sorted after Accounting's sections rather than among them: a company setting up its books reads the
         * currency and the freeze date first, and this one sends email to its customers. 75 rather than 80,
         * which `CompanySettings` gives to the status page — its own comment puts that block last "because it
         * is the least consequential", and sharing a sort would have put this after it. Off by default, which
         * is what makes shipping it safe.
         */
        SettingsSections::register('invoicing.dunning', DunningSettingsSection::class, 75);
        SettingsSections::register('invoicing.credit-control', CreditControlSettingsSection::class, 76);
        SettingsSections::register('invoicing.statements', StatementSettingsSection::class, 77);

        /*
         * What an invoice's postings were for — `docs/erpnext-gap-plan.md` Phase 1.
         *
         * Registered here rather than read by Accounting, for the reason the owners registry above was
         * written: a ledger that named this module would re-create the `accounting -> invoicing` edge four
         * registries were built to remove. An invoice knows its project and its customer; a company without
         * Invoicing registers neither and its ledger reports every entry as unassigned, which is correct.
         */
        LedgerDimensions::register(Invoice::class, fn (Invoice $invoice): array => [
            LedgerDimensions::PROJECT => $invoice->project?->name,
            LedgerDimensions::PARTY => $invoice->contact?->name,
        ], ['project', 'contact']);

        // Invoicing's own three reports. See PayrollReports for the other half of the same change.
        foreach (['AgedReceivables', 'AgedPayables'] as $key) {
            ReportRenderers::register(
                $key,
                fn (string $asOf): array => app(InvoicingReports::class)->ageing($key, $asOf),
            );
        }

        // The customer's side of §153 — what they withheld from receipts, for the company's own return.
        ReportCatalogue::register(
            'Statutory reporting',
            TaxWithheldByCustomers::class,
            'Tax customers deducted from receipts against a certificate, held as advance tax for the return.',
        );
        ReportRenderers::register(
            'TaxWithheldByCustomers',
            fn (string $asOf): array => app(InvoicingReports::class)->taxWithheldByCustomers($asOf),
        );

        ReportRenderers::register(
            'FbrInvoiceReporting',
            fn (string $asOf): array => app(InvoicingReports::class)->fbrReconciliation($asOf),
        );

        DashboardStats::register('invoicing.unpaid', function () {
            if (! auth()->user()?->can('InvoiceView')) {
                return null;
            }

            // Single aggregate query instead of loading every open invoice.
            //
            // Credit notes are subtracted from the money and left out of the count, which is two
            // different decisions. The money has to net or this figure overstates what is owed by every
            // credit outstanding. The count must not, because "3 open" should mean three invoices
            // somebody can chase — counting a credit note among them invites a call about a document the
            // customer is owed rather than owes.
            $open = Invoice::whereIn('kind', [Invoice::KIND_SALE, Invoice::KIND_CREDIT_NOTE])
                ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN kind = ? THEN 1 ELSE 0 END), 0) as cnt, '
                    .'COALESCE(SUM((total - amount_paid) * CASE WHEN kind = ? THEN -1 ELSE 1 END), 0) as outstanding_total',
                    [Invoice::KIND_SALE, Invoice::KIND_CREDIT_NOTE]
                )
                ->first();

            return Stat::make(
                'Unpaid Customer Invoices',
                'PKR '.number_format(round((float) $open->outstanding_total, 2), 2),
            )->description(((int) $open->cnt).' open');
        }, sort: 30);

        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/routes/console.php');
    }
}
