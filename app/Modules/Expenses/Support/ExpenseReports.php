<?php

namespace App\Modules\Expenses\Support;

use App\Modules\Accounting\Support\PayrollAccounts;
use App\Modules\Expenses\Models\ExpenseClaim;
use App\Support\Reporting\ReportPeriod;
use App\Support\Reporting\ReportShapes;
use Illuminate\Support\Collection;

/**
 * Expense claims by state — `docs/reports-expansion-plan.md` Phase 2.8.
 *
 * "By status, employee and period; reimbursed through payroll versus pending, where the pending total is an
 * accrued liability."
 *
 * **The liability is the point of the report**, and since `docs/bexio-gap-plan.md` Phase 3 it is in an
 * account. A claim that is *approved* and not yet paid is money the company owes an employee, and approval
 * now posts it — the claim's own category as the expense, and 2310 Expense Reimbursements Payable for what
 * is owed (`ExpenseClaimPosting`). The payslip's reimbursement line debits that same account on payday, so
 * the liability this report totals and the balance on 2310 are the same figure, for every claim approved
 * since the posting began. Claims approved before it were never accrued and still reach the ledger only
 * through the payslip, which is why the two can differ by exactly that backlog.
 *
 * **The shared account is gone with it.** Reimbursements used to land in `5600`, the same code as
 * `meal_recovery`, where nothing could be attributed to either flow; the shipped mapping now points
 * `expense_reimbursement` at 2310, and a company that kept 5600 has its expense in the claim's category
 * and 5600 washing to nil.
 *
 * A period, not a balance — unlike the other Phase 2 reports. A claim is submitted, decided and paid within
 * weeks, and "what did we reimburse this year" is the question people actually ask of it. The pending total
 * is still as-at, because a liability has no period.
 */
class ExpenseReports
{
    use ReportShapes;

    public function claims(string $asOf): array
    {
        // Through ReportPeriod, never `startOfYear()`. This application's year runs 1 July to 30 June, and
        // a claims report from 1 January is six months of a company's expenses reported as a year — the
        // exact mistake that class exists to prevent.
        $period = ReportPeriod::toDate($asOf);
        $from = $period['from'];
        $to = $period['to'];

        /** @var Collection<int, ExpenseClaim> $claims */
        $claims = ExpenseClaim::query()
            ->whereDate('claimed_on', '>=', $from)
            ->whereDate('claimed_on', '<=', $to)
            ->with('employee.user')
            ->get();

        // Everything approved and unpaid, whenever it was claimed — the liability is a balance and a claim
        // approved last December is owed just as much as one approved yesterday.
        $owed = ExpenseClaim::query()
            ->awaitingSettlement()
            ->whereDate('claimed_on', '<=', $to)
            ->sum('amount');

        $rows = [];

        // Sorted before formatting, not after. The columns are strings by the time they are cells — a
        // thousand separator and an em dash among them — so sorting the rows would have compared "9" against
        // "1,200" as text and put the smallest figure at the top.
        $byEmployee = $claims
            ->groupBy('employee_id')
            ->sortByDesc(fn (Collection $group): float => $this->total($group, ExpenseClaim::STATUS_SETTLED));

        foreach ($byEmployee as $employeeId => $group) {
            $employee = $group->first()->employee;

            $rows[] = [
                (string) ($employee?->display_label ?? 'Employee #'.$employeeId),
                number_format($group->count()),
                $this->amount($group, ExpenseClaim::STATUS_PENDING),
                $this->amount($group, ExpenseClaim::STATUS_APPROVED),
                $this->amount($group, ExpenseClaim::STATUS_SETTLED),
                // Refused is stated rather than dropped: a person whose claims are routinely refused is a
                // conversation, and a report that hid it would be describing a tidier company than exists.
                $this->amount($group, ExpenseClaim::STATUS_REFUSED),
            ];
        }

        $settled = $this->total($claims, ExpenseClaim::STATUS_SETTLED);
        $pending = $this->total($claims, ExpenseClaim::STATUS_PENDING);
        $refused = $this->total($claims, ExpenseClaim::STATUS_REFUSED);

        return $this->table(
            'ExpenseClaimsReport',
            'Expense Claims',
            $this->subtitle('claimed between '.$from.' and '.$to),
            ['Employee', 'Claims', 'Pending', 'Approved', 'Reimbursed', 'Refused'],
            'minmax(0, 1fr) 7rem 10rem 10rem 11rem 10rem',
            [1, 2, 3, 4, 5],
            $rows,
            [
                // The liability first, because it is the figure nothing else in the application states.
                ['label' => 'OWED TO STAFF', 'value' => round((float) $owed, 2), 'accent' => true],
                ['label' => 'REIMBURSED', 'value' => $settled, 'accent' => false],
            ],
            $this->claimsNote($rows, round((float) $owed, 2), $pending, $refused),
            $rows === [] ? null : [
                'Total — '.count($rows).' people',
                number_format($claims->count()),
                number_format($pending, 0),
                number_format($this->total($claims, ExpenseClaim::STATUS_APPROVED), 0),
                number_format($settled, 0),
                number_format($refused, 0),
            ],
            'No claim was made in this period.',
        );
    }

    /**
     * What the figures mean, and the two things a reader has to be told.
     *
     * Where the amount owed sits — the account approval accrues it to, which the payslip clears — and that
     * the balance on that account is this liability only for claims approved since the posting began
     * (docs/bexio-gap-plan.md Phase 3). Claims approved before it reach the ledger through the payslip alone.
     *
     * @param  array<int, array<int, string>>  $rows
     */
    private function claimsNote(array $rows, float $owed, float $pending, float $refused): string
    {
        if ($rows === []) {
            return 'NO CLAIM WAS MADE IN THIS PERIOD';
        }

        return mb_strtoupper(implode(' · ', array_filter([
            count($rows).' people',
            $owed > 0
                ? number_format($owed, 0).' approved and unpaid — accrued in '.PayrollAccounts::code('expense_reimbursement').' until reimbursed'
                : 'nothing approved is unpaid',
            $pending > 0 ? number_format($pending, 0).' still awaiting a decision' : null,
            $refused > 0 ? number_format($refused, 0).' refused' : null,
            'reimbursements clear '.PayrollAccounts::code('expense_reimbursement').' — its balance is this liability, for claims approved since posting began',
        ])));
    }

    /**
     * One status's total for a group, formatted — with a dash where there is none.
     *
     * A dash rather than a nought, consistently with the rest of these reports: nothing pending and nothing
     * claimed at all read differently, and a column of noughts hides which employees are actually waiting.
     *
     * @param  Collection<int, ExpenseClaim>  $group
     */
    private function amount(Collection $group, string $status): string
    {
        $total = $this->total($group, $status);

        return $total > 0 ? number_format($total, 0) : '—';
    }

    /**
     * @param  Collection<int, ExpenseClaim>  $claims
     */
    private function total(Collection $claims, string $status): float
    {
        return round((float) $claims->where('status', $status)->sum('amount'), 2);
    }
}
