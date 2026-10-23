<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Services\CorporateReturnPack;
use App\Support\Reporting\ReportPeriod;
use App\Support\Reporting\ReportShapes;

/**
 * The corporate return pack, drawn in the reports pane.
 *
 * The page is a worksheet beside a statement, and the hub listed it from the day it was built without a
 * renderer — so the explorer fell back to "opens on its own screen", which looks deliberate and is not.
 * ReportPaneTest said so the first time CI reached the suite (2026-10-23). What the pane draws is the
 * statement half: accounting profit to tax due, on the saved worksheet, for the fiscal year containing the
 * date. Changing a rate or an adjustment is the page's job and stays there.
 */
class ReturnPackReports
{
    use ReportShapes;

    public function __construct(private CorporateReturnPack $pack) {}

    /** @return array<string, mixed> */
    public function corporate(string $asOf): array
    {
        $year = ReportPeriod::yearFor($asOf);

        if ($year === null) {
            return $this->table(
                'CorporateReturnPack',
                'Corporate Return Pack',
                $this->subtitle('No fiscal year contains '.$asOf),
                ['Line', 'Amount'],
                '1fr 11rem',
                [1],
                [],
                [],
                'SET UP THE FISCAL YEAR FIRST',
                empty: 'No fiscal year contains this date.',
            );
        }

        $pack = $this->pack->build($year->getKey());
        $worksheet = $pack['worksheet'];

        $rows = [
            ['Accounting profit, per the ledger', $this->amount($pack['pnl']['net_profit'])],
            ['Tax adjustments to accounting profit ('.count($worksheet['adjustments']).' on the worksheet)', $this->amount($pack['adjustments_total'])],
            ['Income after adjustments', $this->amount($pack['income_after_adjustments'])],
            ['Brought-forward loss applied', $this->amount(-$pack['loss_applied'])],
            ['Taxable income', $this->amount($pack['taxable_income'])],
            ['Turnover, for s.113', $this->amount($pack['turnover'])],
            ['Normal tax at '.$worksheet['tax_rate'].'%', $this->amount($pack['normal_tax'])],
            ['Minimum tax at '.$worksheet['minimum_tax_rate'].'% of turnover (s.113)', $this->amount($pack['minimum_tax'])],
            ['Super tax (s.4C)', $this->amount($pack['super_tax'])],
            ['Tax due', $this->amount($pack['tax_due'])],
            ['Tax already paid in the year', $this->amount($pack['tax_paid'])],
        ];

        return $this->table(
            'CorporateReturnPack',
            'Corporate Return Pack',
            $this->subtitle('Tax year '.$year->name),
            ['Line', 'Amount'],
            '1fr 11rem',
            [1],
            $rows,
            [
                ['label' => 'TAXABLE INCOME', 'value' => $pack['taxable_income'], 'accent' => false],
                ['label' => 'TAX DUE', 'value' => $pack['tax_due'], 'accent' => true],
                ['label' => $pack['balance'] >= 0 ? 'BALANCE TO PAY' : 'REFUND DUE', 'value' => abs($pack['balance']), 'accent' => false],
            ],
            mb_strtoupper(($pack['basis'] === 'minimum' ? 'minimum tax on turnover' : 'normal tax on taxable income')
                .' applies this year — the greater of the two; super tax rides on top'),
            ['Balance to pay with the return', $this->amount($pack['balance'])],
        );
    }
}
