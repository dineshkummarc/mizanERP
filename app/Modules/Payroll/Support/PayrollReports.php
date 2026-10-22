<?php

namespace App\Modules\Payroll\Support;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\EobiReturn;
use App\Modules\Payroll\Services\PayrollRegister;
use App\Modules\Payroll\Services\SalaryBankExportService;
use App\Modules\Payroll\Services\WithholdingTaxSummary;
use App\Support\Reporting\ReportPeriod;
use App\Support\Reporting\ReportShapes;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Payroll's three reports, as the Reports explorer draws them.
 *
 * These were methods on `Accounting\Support\ReportPane`, which meant Accounting imported
 * `WithholdingTaxSummary`, `SalaryBankExportService` and `Payslip` in order to render reports it does not
 * own. See docs/module-packaging-plan.md §8 Group A. They are registered with
 * `App\Support\Reporting\ReportRenderers` by PayrollServiceProvider, so a company without Payroll has
 * three fewer reports rather than three that fail.
 *
 * The look is unchanged because the shapes are shared — `ReportShapes` is the same code the pane used,
 * moved to where both can reach it.
 */
class PayrollReports
{
    use ReportShapes;

    /** Tax withheld per employee for the year, with what it was withheld on. */
    public function taxSummary(string $asOf, ?string $month = null): array
    {
        $report = app(WithholdingTaxSummary::class)->summary($this->fiscalYear($asOf)?->getKey(), $month);
        $employees = collect($report['employees'] ?? []);

        return $this->table(
            'TaxSummary',
            'Tax Summary',
            $this->subtitle('fiscal year '.($report['fiscal_year'] ?? '—').($month ? ' · '.$month : ' · the whole year')),
            ['Employee', 'Taxable', 'Tax withheld'],
            'minmax(0, 1fr) 10rem 10rem',
            [1, 2],
            $employees->map(fn (array $row): array => [
                (string) ($row['name'] ?? $row['employee'] ?? '—'),
                number_format((float) ($row['taxable'] ?? 0), 0),
                number_format((float) ($row['tax'] ?? 0), 0),
            ])->all(),
            [
                ['label' => 'TAXABLE TOTAL', 'value' => (float) ($report['taxable_total'] ?? 0), 'accent' => false],
                ['label' => 'TAX WITHHELD', 'value' => (float) ($report['tax_total'] ?? 0), 'accent' => true],
            ],
            mb_strtoupper($employees->count().' employees with tax withheld'),
            // Each figure under the column it totals, which is what a filing is checked against.
            [
                'Total — '.$employees->count().' employees',
                number_format((float) ($report['taxable_total'] ?? 0), 0),
                number_format((float) ($report['tax_total'] ?? 0), 0),
            ],
            $month
                ? "No tax was withheld in {$month}."
                : 'No tax has been withheld in this year yet.',
        );
    }

    /**
     * The withholding statement, summarised rather than produced.
     *
     * The file itself is released from the report's own page, which records the batch — see the `file`
     * kind in ReportPane.
     *
     * @return array<string, mixed>
     */
    public function taxFile(string $asOf): array
    {
        $year = $this->fiscalYear($asOf);

        $payslips = Payslip::query()
            ->when($year !== null, fn ($query) => $query->where('fiscal_year_id', $year->id))
            ->where('withholding_tax', '>', 0);

        return $this->fileReport(
            'FbrTaxFile',
            'FBR Tax File',
            (clone $payslips)->count(),
            (float) $payslips->sum('withholding_tax'),
            'Payslips',
            $this->period($asOf, $year),
        );
    }

    /**
     * Salary payments as a bank upload file, for a payroll month.
     *
     * @return array<string, mixed>
     */
    public function salaryFile(string $asOf): array
    {
        $date = Carbon::parse($asOf);
        $year = $this->fiscalYear($asOf);

        $rows = $year === null ? [] : app(SalaryBankExportService::class)
            ->paymentsForMonth($date->format('F'), $year);

        return $this->fileReport(
            'SalaryBankFile',
            'Salary Bank File',
            count($rows),
            (float) collect($rows)->sum('amount'),
            'Payments',
            $this->period($asOf, $year),
        );
    }

    /**
     * The monthly EOBI return, summarised — `docs/bexio-gap-plan.md` Phase 5, item 17.
     *
     * The month comes from the date, as the salary file's does. The file itself is released from the
     * report's own page.
     *
     * @return array<string, mixed>
     */
    public function eobiReturn(string $asOf): array
    {
        $date = Carbon::parse($asOf);
        $year = $this->fiscalYear($asOf);

        $summary = $year === null
            ? ['employees' => 0, 'grand_total' => 0.0]
            : app(EobiReturn::class)->summary($date->format('F'), $year);

        return $this->fileReport(
            'EobiReturnFile',
            'EOBI Return',
            (int) $summary['employees'],
            (float) $summary['grand_total'],
            'Employees',
            $this->period($asOf, $year),
        );
    }

    /**
     * The fiscal year containing the date.
     *
     * Through `ReportPeriod`, which is the same query this file used to spell out for itself. The rule that
     * a fiscal year is looked up by containment and falls back to the current one belongs in one place: two
     * copies of it is how `PayrollMonth` came to have two implementations that disagreed for every year not
     * starting in July or January.
     */
    /**
     * Employee by pay component for a month, footed against the payroll journal — Phase 2.1.
     *
     * **The month comes from the date**, and the fiscal year from the same date. Payslips are keyed by month
     * *name* plus fiscal year rather than by a date (see `App\Support\PayrollMonth`), so something has to
     * turn one into the other; the pane offers a date and this is the only honest reading of it. A proper
     * month picker for a module's own report is Phase 0.3's `period` filter, still outstanding — until then
     * the report names the month it chose on its own face, which is the part that matters.
     *
     * **Wide, and the first report to need it.** Eleven shipped components plus whatever a company has added
     * is more columns than the pane is wide, and before Phase 0.2 the extra ones were silently clipped.
     */
    public function register(string $asOf): array
    {
        $date = Carbon::parse($asOf);
        $year = $this->fiscalYear($asOf);
        $month = $date->format('F');

        $data = app(PayrollRegister::class)->forMonth($month, $year);

        /** @var Collection<int, PayComponent> $components */
        $components = $data['components'];

        $columns = ['Employee', ...$components->pluck('label')->all(), 'Earnings', 'Deductions', 'Net'];

        $rows = [];

        foreach ($data['employees'] as $payslipId => $employee) {
            $cells = [$employee];

            foreach ($components as $component) {
                $amount = $data['cells'][$payslipId.':'.$component->getKey()] ?? null;
                // A dash rather than a nought: the recorder deletes a component row whose amount rounds to
                // nothing, so a blank cell means "this component was not part of this person's pay" and a
                // nought would claim it was and came to nil.
                $cells[] = $amount === null ? '—' : number_format($amount, 0);
            }

            $cells[] = number_format($data['totals'][$payslipId]['earnings'], 0);
            $cells[] = number_format($data['totals'][$payslipId]['deductions'], 0);
            $cells[] = number_format($data['totals'][$payslipId]['net'], 0);

            $rows[] = $cells;
        }

        $earnings = round(array_sum(array_column($data['totals'], 'earnings')), 2);
        $deductions = round(array_sum(array_column($data['totals'], 'deductions')), 2);
        $net = round(array_sum(array_column($data['totals'], 'net')), 2);

        $footer = ['Total — '.$data['payslips'].' payslips'];

        foreach ($components as $component) {
            $footer[] = number_format($this->columnTotal($data, $component->getKey()), 0);
        }

        $footer[] = number_format($earnings, 0);
        $footer[] = number_format($deductions, 0);
        $footer[] = number_format($net, 0);

        return $this->table(
            'PayrollRegister',
            'Payroll Register',
            $this->subtitle($month.($year?->name ? ' '.$year->name : '')),
            $columns,
            'minmax(12rem, 16rem) '.str_repeat('7.5rem ', $components->count()).'8rem 8rem 8rem',
            range(1, count($columns) - 1),
            $rows,
            [
                ['label' => 'NET PAY', 'value' => $net, 'accent' => true],
                // What the ledger says, beside it. The comparison is the report — Phase 2's rule is that
                // each of its reports ties to a balance, and a reader should not have to hold one of the
                // two figures in their head to make it.
                ['label' => 'SALARIES PAYABLE', 'value' => $data['ledger_available'] ? $data['ledger_net'] : 0.0, 'accent' => false],
            ],
            $this->registerNote($data, $net),
            $rows === [] ? null : $footer,
            'No payslip exists for '.$month.'.',
            wide: true,
        );
    }

    /**
     * Whether the register ties to the payroll journal, and if not, why not.
     *
     * The order of these cases is the order they matter in. An unposted payslip is the everyday cause and
     * has a name, so it is said first and the difference is not called a discrepancy. A difference with
     * everything posted is the one worth investigating, and that is what it is called.
     *
     * @param  array<string, mixed>  $data
     */
    private function registerNote(array $data, float $net): string
    {
        if ($data['payslips'] === 0) {
            return 'NO PAYSLIP EXISTS FOR THIS MONTH';
        }

        if (! $data['ledger_available']) {
            return mb_strtoupper($data['payslips'].' payslips · the ledger comparison is unavailable: '
                .'the payroll account mapping is incomplete');
        }

        $difference = round($net - (float) $data['ledger_net'], 2);

        if ($data['unposted'] > 0) {
            return mb_strtoupper(sprintf(
                '%d payslips · %d not posted, so %s of this is not in the ledger yet',
                $data['payslips'],
                $data['unposted'],
                number_format(abs($difference), 0),
            ));
        }

        return mb_strtoupper(abs($difference) < 0.01
            ? $data['payslips'].' payslips · net pay agrees with salaries payable'
            : sprintf(
                '%d payslips · net pay and salaries payable differ by %s with everything posted — '
                .'a payslip changed after posting, or an entry edited by hand',
                $data['payslips'],
                number_format(abs($difference), 2),
            ));
    }

    /**
     * One component's total across the month.
     *
     * Summed from the cells rather than asked of the database again, so the record row totals exactly what
     * is on the screen above it — which is the only reason a reader can check the column by adding it up.
     *
     * @param  array<string, mixed>  $data
     */
    private function columnTotal(array $data, int $componentId): float
    {
        $total = 0.0;

        foreach (array_keys($data['employees']) as $payslipId) {
            $total += $data['cells'][$payslipId.':'.$componentId] ?? 0.0;
        }

        return round($total, 2);
    }

    private function fiscalYear(string $asOf): ?FiscalYear
    {
        return ReportPeriod::yearFor($asOf);
    }

    private function period(string $asOf, ?FiscalYear $year): string
    {
        return $year?->name
            ? 'fiscal year '.$year->name
            : 'as of '.Carbon::parse($asOf)->format('j M Y');
    }
}
