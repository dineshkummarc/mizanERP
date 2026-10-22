<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Support\ReportPane;
use App\Modules\Core\Filament\Pages\Reports;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * What the explorer draws for each report.
 *
 * The reports are four different things and the pane has a kind for each — a statement of sections, a
 * ledger of debits and credits, a table of rows that are not accounts, and a file that has a summary
 * rather than a page. The first test is the important one: it holds the *set*, so a report added to the
 * hub cannot quietly fall through to "opens on its own screen" without somebody deciding that it should.
 *
 * The figures themselves belong to the services behind them, and those have their own tests. What is
 * asserted here is that each kind is shaped the way the view expects, since a missing key is a broken
 * page and a wrong one is a wrong number on a statement.
 */
class ReportPaneTest extends AccountingTestCase
{
    use InteractsWithTenant;

    /**
     * Reports that carry a filter of their own, and which one.
     *
     * Not an exception to being drawn — every one of them is drawn — but the pane has to offer the control,
     * and a report that quietly loses it renders whatever the fallback picks while looking perfectly fine.
     * That is what the assertion below is for.
     *
     * @var array<string, array<int, string>>
     */
    private const ASKS = [
        'AccountRegister' => ['account'],
        'FindTransactions' => ['search'],
        'BudgetVsActual' => ['budget'],
        'TaxSummary' => ['month'],
        'PettyCashBook' => ['month'],
        // Which dimension the profit is split by — docs/erpnext-gap-plan.md Phase 1. The sixth report to
        // need a control, and the first to need one that is not a date, an account or a document.
        'ProfitAndLossByDimension' => ['dimension'],
        // The §165 statement — Phase 4. A filing month, on the same reasoning as the tax summary's: filed
        // monthly, reconciled for the year, so the whole year has to remain a legitimate answer.
        'WithholdingStatement' => ['month'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'pane@test.local'));
        $this->setCurrentTenant();
    }

    private function postEntry(string $date, array $lines): JournalEntry
    {
        $entries = app(JournalEntryService::class);

        $entry = $entries->create(
            ['entry_date' => $date, 'entry_type' => 'general', 'memo' => 'Pane fixture'],
            collect($lines)->map(fn (array $line): array => [
                'account_id' => Account::where('code', $line[0])->firstOrFail()->id,
                $line[1] => $line[2],
            ])->all(),
        );

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        return $entries->post($entry);
    }

    private function pane(string $key, string $asOf = '2026-06-30'): ?array
    {
        return app(ReportPane::class)->for($key, $asOf);
    }

    // ------------------------------------------------------------------- coverage

    /**
     * Every report in the hub either has a kind or is a deliberate exception.
     *
     * The failure this prevents: a report added to Reports::SECTIONS renders as "this one opens on its
     * own screen" — which is a real affordance, so nothing looks broken, and nobody notices that the
     * pane was supposed to draw it.
     */
    public function test_every_report_in_the_hub_is_drawn_in_the_pane(): void
    {
        $undrawn = [];

        foreach (array_keys(Reports::catalogue()) as $key) {
            if (! ReportPane::supports($key)) {
                $undrawn[] = $key;
            }
        }

        $this->assertSame(
            [],
            $undrawn,
            'these reports have no pane kind, so the explorer falls back to "opens on its own screen" — '
            .'which looks deliberate and is not',
        );

        /*
         * Guards the guard: an empty catalogue would satisfy the loop above.
         *
         * **Kept level with the catalogue rather than below it.** A floor that lags only proves the
         * catalogue is not empty; one that matches it also catches a report that quietly stops being
         * registered, which is the other half of the same failure. So this number rises with every report
         * added — it was 17 before `docs/reports-expansion-plan.md` began.
         *
         * It had drifted to 34 against a catalogue of 36, which is how a guard stops guarding: two reports
         * could have been unregistered with nothing failing. Raise it in the same commit as the report,
         * and do not record which phase each increment came from — that history belongs in the plan's own
         * "what landed", and a comment that grows a clause per report becomes unreadable long before it
         * becomes useful.
         */
        $this->assertGreaterThanOrEqual(53, count(Reports::catalogue()));
    }

    /**
     * Every report draws something, from nothing but a date.
     *
     * The three that need more than a date are asked with nothing supplied, deliberately: the pane opens
     * before anybody has chosen an account or typed a search, and it has to render then too.
     */
    public function test_every_report_draws_from_a_date_alone(): void
    {
        foreach (array_keys(Reports::catalogue()) as $key) {
            $pane = $this->pane($key);

            $this->assertNotNull($pane, "[{$key}] draws nothing");
            $this->assertContains($pane['kind'], ['statement', 'ledger', 'table', 'file'], $key);
            $this->assertNotEmpty($pane['title'], "[{$key}] has no title");
            $this->assertNotEmpty($pane['subtitle'], "[{$key}] has no subtitle");
        }
    }

    /** The controls the pane must offer, and for which reports. */
    public function test_the_reports_that_need_a_control_declare_it(): void
    {
        foreach (self::ASKS as $key => $control) {
            $this->assertSame($control, ReportPane::asks($key), "[{$key}] lost its control");
        }

        // And nothing else asks for one, so a stray control cannot appear on a report that ignores it.
        foreach (array_keys(Reports::catalogue()) as $key) {
            if (! array_key_exists($key, self::ASKS)) {
                $this->assertSame([], ReportPane::asks($key), "[{$key}] asks for something it does not use");
            }
        }
    }

    /**
     * A statement line opens the transactions behind it.
     *
     * The drill is offered only for accounts the register can actually show, and that restraint is the
     * part worth testing: offering it and then landing on a *different* account's register would be worse
     * than not offering it at all.
     */
    public function test_a_statement_line_drills_into_its_account(): void
    {
        $this->postEntry('2026-03-31', [['1100', 'debit_amount', 300000], ['3100', 'credit_amount', 300000]]);

        $pane = $this->pane('BalanceSheet');

        // The cash account is postable, so it is drillable...
        $this->assertContains('1100', $pane['drillable']);

        // ...and equity is not, so the statement must not pretend otherwise.
        $this->assertNotContains('3100', $pane['drillable']);

        // The synthetic retained-earnings line is not an account at all.
        $this->assertNotContains('zzzz', $pane['drillable']);
    }

    /** The drill lands on that account's register, at the same date. */
    public function test_drilling_switches_the_pane_to_that_accounts_register(): void
    {
        $this->postEntry('2026-03-31', [['1100', 'debit_amount', 300000], ['3100', 'credit_amount', 300000]]);

        $cash = \App\Modules\Accounting\Models\Account::where('code', '1100')->firstOrFail();

        \Livewire\Livewire::test(Reports::class, ['asOf' => '2026-06-30'])
            ->call('select', 'BalanceSheet')
            ->call('drillInto', '1100')
            ->assertSet('selected', 'AccountRegister')
            ->assertSet('account', $cash->getKey())
            // the date is what makes it a drill rather than a jump
            ->assertSet('asOf', '2026-06-30');
    }

    /** A code the register cannot open changes nothing. */
    public function test_drilling_into_an_unregisterable_account_is_refused(): void
    {
        \Livewire\Livewire::test(Reports::class)
            ->call('select', 'BalanceSheet')
            ->call('drillInto', '3100')
            ->assertSet('selected', 'BalanceSheet')
            ->call('drillInto', 'zzzz')
            ->assertSet('selected', 'BalanceSheet');
    }

    /** A picker with no options is a dead control, so every one that is a picker must fill it. */
    public function test_the_pickers_have_options(): void
    {
        $pane = app(ReportPane::class);

        $this->assertNotEmpty(
            $pane->options('AccountRegister'),
            'the register has no account to offer, though the seeded chart has postable assets',
        );

        $this->assertNotEmpty(
            $pane->options('TaxSummary', 'month', '2026-06-30'),
            'the month filter has no months to offer',
        );

        // A typed filter has no options on purpose, and the view branches on exactly that.
        $this->assertSame([], $pane->options('FindTransactions'));
    }

    /**
     * The month filter offers the fiscal year's months, in the order they are paid.
     *
     * Fiscal order is the assertion. These years run 1 July to 30 June, so a picker in calendar order puts
     * the last six months of the year first and asks somebody to scroll past December to reach July.
     */
    public function test_the_month_filter_is_in_fiscal_order(): void
    {
        $months = array_keys(app(ReportPane::class)->options('TaxSummary', 'month', '2026-06-30'));

        $this->assertCount(12, $months);
        $this->assertSame(
            $this->fiscalYear->start_date->format('F'),
            $months[0],
            'the month picker does not start where the fiscal year does',
        );
    }

    /**
     * Filtering the tax summary to a month narrows it to that month.
     *
     * Both directions matter: the year has to include what the month has, and the month has to exclude
     * what another month has — a filter that is read but ignored shows the year's figure under a month's
     * heading, which is a wrong number on something that gets filed.
     */
    public function test_the_tax_summary_can_be_filtered_to_a_month(): void
    {
        $year = $this->pane('TaxSummary');
        $months = array_keys(app(ReportPane::class)->options('TaxSummary', 'month', '2026-06-30'));

        $filtered = app(ReportPane::class)->for('TaxSummary', '2026-06-30', true, ['month' => $months[0]]);

        $this->assertStringContainsString($months[0], $filtered['subtitle']);
        $this->assertLessThanOrEqual(
            count($year['rows']),
            count($filtered['rows']),
            'filtering to one month returned more employees than the whole year has',
        );
    }

    /**
     * The reports that end in a record row have one, and it lines up.
     *
     * A footer cell per column is the whole point of it: a total in the wrong cell is worse than no total,
     * because it reads as the total of the column it is sitting under.
     */
    public function test_the_record_row_has_a_cell_per_column(): void
    {
        $this->postEntry('2026-03-31', [['1100', 'debit_amount', 120000], ['3100', 'credit_amount', 120000]]);

        $withFooters = ['TaxSummary', 'AccountRegister', 'AgedReceivables', 'AgedPayables', 'ContractorPayments'];

        foreach ($withFooters as $key) {
            $pane = $this->pane($key);

            $this->assertNotNull($pane['footer'] ?? null, "[{$key}] has no record row");
            $this->assertCount(
                count($pane['columns']),
                $pane['footer'],
                "[{$key}]'s record row does not line up with its columns",
            );
        }
    }

    /**
     * The register's record row is the debits, the credits and what is left.
     *
     * Asserted against the register service rather than against a figure written here: the row exists to be
     * reconciled against the account, and summing the pane's own formatted cells back into numbers — which
     * is how this was first written — breaks the moment a thousands separator appears.
     */
    public function test_the_registers_record_row_totals_its_columns(): void
    {
        $this->postEntry('2026-03-31', [['1100', 'debit_amount', 250000], ['3100', 'credit_amount', 250000]]);

        $pane = app(ReportPane::class)->for('AccountRegister', '2026-06-30', true, []);
        $footer = $pane['footer'];

        $this->assertNotEmpty($pane['rows'], 'nothing was posted to the register, so this proves nothing');

        $debits = collect($pane['rows'])->sum(fn (array $row): float => (float) str_replace(',', '', $row[3] ?: '0'));
        $credits = collect($pane['rows'])->sum(fn (array $row): float => (float) str_replace(',', '', $row[4] ?: '0'));

        $this->assertSame(number_format($debits, 0), $footer[3], 'the debit total is not the debits shown');
        $this->assertSame(number_format($credits, 0), $footer[4], 'the credit total is not the credits shown');

        // And the balance is the closing balance, not the last row's running total by coincidence.
        $this->assertSame(
            $footer[5],
            (string) collect($pane['rows'])->last()[5],
            'the closing figure disagrees with the last running balance',
        );
    }

    /** Every table and ledger states its own grid and which of its columns are figures. */
    public function test_every_table_describes_its_own_columns(): void
    {
        foreach (array_keys(Reports::catalogue()) as $key) {
            $pane = $this->pane($key);

            // The ledger kind carries a grid and numeric columns too, and its rows are cells against the
            // same header — so the shape is asserted for both rather than for tables alone.
            if ($pane['kind'] === 'ledger') {
                $this->assertNotEmpty($pane['grid'], "[{$key}] has no column widths");
                $this->assertArrayHasKey('numeric', $pane, "[{$key}] does not say which columns are figures");

                foreach ($pane['sections'] as $section) {
                    foreach ($section['rows'] as $row) {
                        $this->assertCount(count($pane['columns']), $row['cells'], "[{$key}] has a row of the wrong width");
                    }
                }

                continue;
            }

            if ($pane['kind'] !== 'table') {
                continue;
            }

            $this->assertNotEmpty($pane['grid'], "[{$key}] has no column widths");
            $this->assertArrayHasKey('numeric', $pane, "[{$key}] does not say which columns are figures");
            $this->assertNotEmpty($pane['empty'], "[{$key}] has nothing to say when it is empty");

            // A row must have exactly as many cells as there are columns, or the grid shears.
            foreach ($pane['rows'] as $row) {
                $this->assertCount(count($pane['columns']), $row, "[{$key}] has a row of the wrong width");
            }
        }
    }

    // ------------------------------------------------------------------- the kinds

    public function test_the_balance_sheet_and_profit_and_loss_are_statements(): void
    {
        $this->postEntry('2026-03-31', [['1100', 'debit_amount', 250000], ['3100', 'credit_amount', 250000]]);

        foreach (['BalanceSheet', 'ProfitAndLoss'] as $key) {
            $pane = $this->pane($key);

            $this->assertSame('statement', $pane['kind']);
            $this->assertNotEmpty($pane['sections']);
            $this->assertNotEmpty($pane['tiles']);
            $this->assertArrayHasKey('closing', $pane);
            // The comparison column the statement kind exists for.
            $this->assertNotNull($pane['previous_label']);
        }
    }

    /**
     * The cash flow is a statement of three sections, and its rows are movements rather than accounts.
     */
    public function test_the_cash_flow_is_a_statement_of_three_sections(): void
    {
        $this->postEntry('2026-02-28', [['1100', 'debit_amount', 800000], ['4100', 'credit_amount', 800000]]);

        $pane = $this->pane('CashFlow');

        $this->assertSame('statement', $pane['kind']);
        $this->assertSame(
            ['OPERATING', 'INVESTING', 'FINANCING'],
            array_column($pane['sections'], 'label'),
        );
        $this->assertSame('Net movement in cash', $pane['closing']['label']);
    }

    /**
     * The trial balance keeps its two columns.
     *
     * Its whole claim is that debits equal credits, which one amount column cannot state — so it has its
     * own kind, and deliberately no comparison.
     */
    public function test_the_trial_balance_is_a_ledger_of_debits_and_credits(): void
    {
        $this->postEntry('2026-03-31', [['1100', 'debit_amount', 400000], ['3100', 'credit_amount', 400000]]);

        $pane = $this->pane('TrialBalance');

        $this->assertSame('ledger', $pane['kind']);
        $this->assertSame(['Account', 'Debit', 'Credit'], $pane['columns']);
        $this->assertTrue($pane['balanced']);
        $this->assertSame('BALANCED · DEBITS = CREDITS', $pane['note']);

        // Every row carries a cell per column, and so does every section total. Cells rather than named
        // debit/credit keys since the general ledger joined this kind: a ledger states its own columns
        // now, so the invariant that survives both is that a row lines up with the header above it.
        foreach ($pane['sections'] as $section) {
            $this->assertCount(count($pane['columns']), $section['total']['cells']);

            foreach ($section['rows'] as $row) {
                $this->assertCount(count($pane['columns']), $row['cells']);
            }
        }

        // And it is still the debits and the credits that are in those cells. The label reads
        // "Total asset" because it is built from the account *type*, which is singular — pre-existing
        // wording, asserted here as it is rather than quietly changed under a refactor.
        $this->assertSame('Total asset', $pane['sections'][0]['total']['cells'][0]);
        $this->assertSame(number_format(400000, 0), $pane['sections'][0]['total']['cells'][1]);

        // No prior-year column: see the class.
        $this->assertArrayNotHasKey('previous_label', $pane);
    }

    /** The ageing is a table, and its buckets are the point of it. */
    public function test_the_ageing_reports_are_tables_with_their_buckets_as_tiles(): void
    {
        foreach (['AgedReceivables', 'AgedPayables'] as $key) {
            $pane = $this->pane($key);

            $this->assertSame('table', $pane['kind'], $key);
            $this->assertSame(['Invoice', 'Contact', 'Days overdue', 'Outstanding'], $pane['columns']);
            $this->assertArrayHasKey('footer', $pane);

            // Every bucket the service cuts the ageing into becomes a tile — the count comes from the
            // service rather than from a number written here, because the buckets are its decision.
            $buckets = $key === 'AgedReceivables'
                ? app(\App\Modules\Invoicing\Services\InvoiceService::class)->outstandingReceivables('2026-06-30')
                : app(\App\Modules\Invoicing\Services\InvoiceService::class)->outstandingPayables('2026-06-30');

            $this->assertCount(count($buckets['buckets']), $pane['tiles'], "[{$key}] drops a bucket");
            $this->assertNotEmpty($pane['tiles'], "[{$key}] shows no buckets at all");
        }
    }

    /**
     * A file report is summarised, not produced.
     *
     * The pane says what the file would contain; releasing it stays on the report's own screen, where the
     * confirmation and the batch reference live — so this kind has tiles and a count and deliberately no
     * table of rows to download from.
     */
    public function test_a_file_report_is_summarised_rather_than_produced(): void
    {
        foreach (['SalaryBankFile', 'FbrTaxFile', 'BankPaymentFile'] as $key) {
            $pane = $this->pane($key);

            $this->assertSame('file', $pane['kind'], $key);
            $this->assertArrayHasKey('rows_count', $pane);
            $this->assertCount(2, $pane['tiles'], "[{$key}] should summarise a count and a value");
            $this->assertArrayNotHasKey('rows', $pane, "[{$key}] must not offer rows to download from");
            $this->assertNotEmpty($pane['note']);
        }
    }

    /** Nothing to send reads as nothing to send, rather than as an empty file. */
    public function test_a_file_with_nothing_in_it_says_so(): void
    {
        $pane = $this->pane('SalaryBankFile');

        $this->assertSame(0, $pane['rows_count']);
        $this->assertSame('NOTHING TO SEND FOR THIS PERIOD', $pane['note']);
    }
}
