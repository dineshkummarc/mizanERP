<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\EmployeeSettingComponent;
use App\Modules\Payroll\Models\Payslip;
use App\Support\Contracts\AdvanceLedger;
use App\Support\Contracts\ReimbursableClaims;
use App\Support\PayrollMonth;
use App\Support\Pdf\Pdf;
use App\Support\Pdf\PdfDocument;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PayslipService
{
    public function __construct(private TaxCalculatorService $taxCalculator) {}

    /**
     * This month's advance instalment, or 0.0 when the employee has none.
     *
     * Asked of the `AdvanceLedger` contract rather than of `Advances\Services\AdvanceService`, which Payroll
     * named directly — a two-cycle, since Advances *requires* Payroll. With no ledger bound, or the module
     * unlicensed, this is 0.0 and payroll carries on with the settings figure exactly as before. See
     * docs/module-packaging-plan.md §11.
     */
    protected function advanceInstalmentFor($employeeId, ?int $excludingPayslipId = null, ?string $periodOn = null): float
    {
        return app(AdvanceLedger::class)->instalmentFor($employeeId, $excludingPayslipId, $periodOn);
    }

    /**
     * What this employee is owed back in approved expense claims, or 0.0 when there is no claims process —
     * the same shape as the advance ledger above, so payroll works with or without Expenses installed.
     */
    protected function expenseClaimsFor($employeeId, ?int $payslipId = null): float
    {
        return app(ReimbursableClaims::class)->reimbursableFor($employeeId, $payslipId);
    }

    /**
     * The parts of pay that come from components rather than columns.
     *
     * Everything the system shipped with is still column-backed and read from its
     * column; this is what makes an allowance added afterwards work without touching
     * any of the arithmetic below. Taxable earnings are returned separately because
     * the annual projection needs them and a non-taxable one — a reimbursement of
     * somebody's own money — must not raise their tax.
     *
     * @return array{earnings: float, taxable_earnings: float, deductions: float}
     */
    protected function componentTotals(?EmployeeSetting $setting, string $month = '', ?FiscalYear $fiscalYear = null, bool $projecting = false): array
    {
        $empty = ['earnings' => 0.0, 'taxable_earnings' => 0.0, 'deductions' => 0.0];

        if (! $setting) {
            return $empty;
        }

        $rows = EmployeeSettingComponent::with('component')
            ->where('employee_setting_id', $setting->getKey())
            ->get()
            ->filter(fn (EmployeeSettingComponent $row): bool => $row->component
                && $row->component->is_active
                && ! $row->component->is_column_backed);

        // What each component pays *this month* — an hourly wage is hours times the rate, a 13th
        // salary is whole in its month and nothing in the others (docs/bexio-gap-plan.md Phase 5,
        // item 20). The annual projection asks for a month's worth instead, so a lump sum is spread
        // over the year it is taxed in rather than multiplied by the months ahead.
        $amounts = app(ComponentAmount::class);

        foreach ($rows as $row) {
            $amount = $projecting
                ? $amounts->projectedMonthly($row, $setting->employee_id, $month, $fiscalYear)
                : $amounts->monthly($row, $setting->employee_id, $month, $fiscalYear);

            if ($row->component->isEarning()) {
                $empty['earnings'] = round($empty['earnings'] + $amount, 2);

                if ($row->component->is_taxable) {
                    $empty['taxable_earnings'] = round($empty['taxable_earnings'] + $amount, 2);
                }

                continue;
            }

            $empty['deductions'] = round($empty['deductions'] + $amount, 2);
        }

        return $empty;
    }

    /**
     * Calculate payslip data based on employee settings for a specific month and fiscal year using date ranges.
     *
     * ── `$attendance`: the phase 3 and 3a join ──────────────────────────────────
     *
     * ONE new input rather than seven, carrying what the payslip knows about the
     * month. Seven positional parameters on a method that already has eleven would be
     * seven chances to pass them in the wrong order, and the failure would be a wrong
     * payslip rather than a type error.
     *
     * Keys, all optional:
     *   total_working_days, lop_days       — the pro-rating inputs
     *   proration_divisor, proration_basis_days
     *                                      — what a payslip ALREADY recorded. Present
     *                                        means "reproduce this exactly", which is
     *                                        what stops a settled month moving when the
     *                                        company later changes the divisor.
     *   overtime_minutes                   — what attendance recorded
     *   overtime_hourly_rate, overtime_multiplier
     *                                      — likewise already-recorded, same reason
     *
     * With `payroll.prorate_on_attendance` off — the default — every one of these is
     * ignored and this method behaves exactly as it did before phase 3. That is what
     * PayslipAttendanceProrationTest holds in place.
     *
     * @param  array<string, mixed>|null  $attendance
     */
    public function calculateByParams(
        $employeeId, $month, $fiscalYearId, $bonus = null, $extraWorkHours = null,
        $deviceAllowance = null, $petrolAllowance = null, $advances = null, $mealDeduction = null, $esiInsurance = null, $expenseReimbursement = null,
        ?int $payslipId = null, ?array $attendance = null
    ) {
        $fiscalYear = FiscalYear::find($fiscalYearId);

        $targetDate = PayrollMonth::firstDay($month, $fiscalYear)->toDateString();

        $setting = EmployeeSetting::getActiveSettingForDate($employeeId, $targetDate, $fiscalYearId);

        if (! $setting) {
            return array_fill_keys(['basic_wage', 'medical_allowance', 'device_allowance', 'petrol_allowance', 'advances', 'meal_deduction', 'esi_health_insurance', 'bonus', 'extra_work_hours', 'expense_reimbursement', 'total_earnings', 'withholding_tax', 'total_deductions', 'net_salary'], 0);
        }

        $data = [
            'basic_wage' => (float) $setting->basic_wage,
            'medical_allowance' => (float) $setting->medical_allowance,
            'device_allowance' => ((float) $deviceAllowance > 0) ? (float) $deviceAllowance : (float) $setting->device_allowance,
            'petrol_allowance' => ((float) $petrolAllowance > 0) ? (float) $petrolAllowance : (float) $setting->petrol_allowance,
            // From the advance ledger when the employee has one, so the figure
            // deducted and the balance still owed are the same fact. An explicit
            // amount passed in still wins — a payroll clerk overriding one month
            // is a legitimate correction — and an employee with no advance falls
            // back to their settings exactly as before.
            // The month is passed because the ledger's answer depends on it: an
            // advance can start recovering later than it was given, and can skip a
            // month.
            'advances' => ((float) $advances > 0)
                ? (float) $advances
                : ($this->advanceInstalmentFor($employeeId, $payslipId, $targetDate) ?: (float) $setting->advances),
            'meal_deduction' => ((float) $mealDeduction > 0) ? (float) $mealDeduction : (float) $setting->meal_deduction,
            'esi_health_insurance' => ((float) $esiInsurance > 0) ? (float) $esiInsurance : (float) $setting->esi_health_insurance,
            'bonus' => ((float) $bonus > 0) ? (float) $bonus : (float) ($setting->bonus ?? 0),
            'extra_work_hours' => ((float) $extraWorkHours > 0) ? (float) $extraWorkHours : (float) ($setting->extra_work_hours ?? 0),
            // From the approved claims when there are any, so the figure on the
            // payslip is the sum of things somebody approved rather than a number
            // typed with nothing behind it. An explicit amount still wins: paying a
            // reimbursement outside the claim process is a legitimate correction.
            'expense_reimbursement' => ((float) $expenseReimbursement > 0)
                ? (float) $expenseReimbursement
                : $this->expenseClaimsFor($employeeId, $payslipId),
        ];

        // ── Phase 3a: overtime, before pro-rating ────────────────────────────────
        //
        // Before, because the two answer different questions and must not compound:
        // pro-rating reduces the agreed package for days not worked, and overtime pays
        // for hours worked *beyond* it. Scaling an overtime payment by the same factor
        // would reduce pay for time somebody actually gave.
        //
        // Follows the pattern `advances` and `expense_reimbursement` already set: from
        // the records when there are records, with an explicit amount still winning
        // because a clerk overriding one month is a legitimate correction.
        $overtime = $this->overtimeFor($employeeId, $month, $fiscalYear, $attendance);

        if ($overtime && (float) $extraWorkHours <= 0) {
            $data['extra_work_hours'] = $overtime['amount'];
        }

        $data['overtime_minutes'] = $overtime['minutes'] ?? null;
        $data['overtime_hourly_rate'] = $overtime['hourly_rate'] ?? null;
        $data['overtime_multiplier'] = $overtime['multiplier'] ?? null;

        // Allowances and deductions added as components rather than columns.
        $components = $this->componentTotals($setting, $month, $fiscalYear);
        $data['component_earnings'] = $components['earnings'];
        $data['component_deductions'] = $components['deductions'];

        // ── Phase 3: pro-rating ──────────────────────────────────────────────────
        //
        // Applied HERE, on the component amounts, and never to the totals below.
        //
        // Scaling basic_wage, total_earnings and net_salary after the calculation is
        // the obvious shortcut and it breaks the ledger: PayrollPostingService books
        // debits from the earning figures and credits from the deductions and net
        // payable, so scaling one side leaves the other where it was and payslip
        // creation dies mid-run on "Entry is not balanced". That was measured by
        // deliberately mutating Payslip::booted(); PayslipCalculationSeamTest exists
        // to catch anybody who tries it again.
        //
        // Deductions are deliberately untouched. Tax follows the reduced gross of its
        // own accord, because the annual projection below reads $data['total_earnings']
        // — which is computed after this block from the already-scaled figures.
        $basis = $this->prorationBasisFor($month, $fiscalYear, $attendance);

        if ($basis) {
            // **Only the basic wage, of the fixed columns.** That is the conservative
            // reading and a deliberate one: §5 says a fixed medical or device allowance
            // often does NOT pro-rate, and those columns have no `prorates` flag to ask
            // — so scaling them would be deciding on the company's behalf, in the
            // direction that costs the employee.
            //
            // `bonus` and `extra_work_hours` are untouched for stronger reasons: a
            // bonus is discretionary and already a decided amount, and overtime is
            // time actually worked (scaling it would charge somebody for their own
            // absence twice).
            //
            // A company that wants an allowance to scale moves it to a pay component
            // and sets `prorates` — which is what "pay as data, not columns" is for,
            // and it is already how this application prefers allowances to be
            // expressed.
            $data['basic_wage'] = $basis->apply($data['basic_wage']);

            // Only the components a company said should scale. `prorates` defaults
            // false, so an existing allowance keeps paying in full until somebody
            // decides otherwise.
            $prorated = $this->prorateComponents($setting, $basis, $components);
            $components['earnings'] = $prorated['earnings'];
            $components['taxable_earnings'] = $prorated['taxable_earnings'];

            $data['component_earnings'] = $components['earnings'];

            $data['proration_divisor'] = $basis->divisor;
            $data['proration_basis_days'] = $basis->basisDays;
        } else {
            // Null rather than absent, so a payslip that stops being pro-rated —
            // because attendance was corrected to a full month — clears what it
            // recorded rather than keeping a divisor it no longer used.
            $data['proration_divisor'] = null;
            $data['proration_basis_days'] = null;
        }

        // What of this month's earnings is taxable. Only components can be
        // non-taxable, so this is the month's total less the untaxed part of them —
        // and the annual projection below has to use it rather than total_earnings,
        // or a reimbursement in the current month would raise the year's tax while
        // the same reimbursement in any other month would not.
        $untaxedComponents = round($components['earnings'] - $components['taxable_earnings'], 2);

        // Current Month Total Earnings Base (Form values ke sath)
        $totalEarningsBase = $data['basic_wage'] + $data['petrol_allowance'] + $data['device_allowance'] + $data['bonus'] + $data['extra_work_hours'];
        $data['total_earnings'] = $totalEarningsBase + $data['medical_allowance'] + $components['earnings'];

        /*
         * What this month has that the months ahead of it do not — and must not be projected onto them.
         *
         * The projection below stands this month in for every month not yet paid, which is right for a
         * salary and wrong for a bonus: a 100,000 bonus in July would read as a company promising 100,000 a
         * month, and July's deduction would be a twelfth of a tax bill nobody owes. The *year* came out
         * right even so — the true-up further down recomputes from what was actually paid and gives the
         * excess back over the following months — so what this excludes is not an annual error but the
         * lump: with it gone, the tax on a one-off is spread over the remaining months like everything
         * else, instead of being taken in the month the bonus was paid.
         *
         * **Only the genuinely one-off part.** A bonus or an overtime figure that is on the employee's
         * *settings* is recurring — the company put it on the package — so only the excess over that counts.
         * An allowance corrected on this payslip is deliberately left projected: a corrected allowance is
         * usually the new normal, and treating it as temporary would under-withhold all year.
         *
         * Pro-rating is the same shape with the opposite sign — a month of unpaid leave is projected as if
         * every month were short — and is left alone here. It under-withholds now and trues up later, which
         * is the direction that does not take somebody's money early.
         */
        $oneOffEarnings = round(
            max(0, $data['bonus'] - (float) ($setting->bonus ?? 0))
            + max(0, $data['extra_work_hours'] - (float) ($setting->extra_work_hours ?? 0)),
            2,
        );

        $previousEarningsSum = Payslip::where('employee_id', $employeeId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('month', '!=', $month)
            ->sum('total_earnings');

        $completedMonths = Payslip::where('employee_id', $employeeId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('month', '!=', $month)
            ->pluck('month')
            ->toArray();

        $allSettings = EmployeeSetting::where('employee_id', $employeeId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->get();

        $annualTotalEarnings = $previousEarningsSum;

        if ($allSettings->isNotEmpty()) {
            foreach ($allSettings as $empSetting) {
                $sMonthlyTotal = (float) $empSetting->basic_wage
                                 + (float) $empSetting->medical_allowance
                                 + (float) $empSetting->petrol_allowance
                                 + (float) $empSetting->device_allowance
                                 + (float) ($empSetting->bonus ?? 0)
                                 + (float) ($empSetting->extra_work_hours ?? 0)
                                 // Taxable components only: a reimbursement is not
                                 // income and must not raise anybody's tax.
                                 + $this->componentTotals($empSetting, $month, $fiscalYear, projecting: true)['taxable_earnings'];

                if ($empSetting->id == $setting->id) {
                    // This month's figures stand in for the rest of the year, so the
                    // untaxed part of them has to come out here too. Without it a
                    // non-taxable component raised the year's tax through the eleven
                    // months it was projected across, having been correctly excluded
                    // from the one month it was actually paid in.
                    //
                    // And so does anything one-off — see $oneOffEarnings above. The months ahead are being
                    // described, and they have no bonus in them.
                    $sMonthlyTotal = $data['total_earnings'] - $untaxedComponents - $oneOffEarnings;
                }

                $sStart = Carbon::parse($empSetting->start_date);
                $sEnd = Carbon::parse($empSetting->end_date);

                $currentPeriodCursor = $sStart->copy();
                while ($currentPeriodCursor <= $sEnd) {
                    $mName = $currentPeriodCursor->format('F'); //  July, August

                    if ($mName === $month || ! in_array($mName, $completedMonths)) {
                        $annualTotalEarnings += ($mName === $month)
                            ? $data['total_earnings'] - $untaxedComponents
                            : $sMonthlyTotal;
                    }

                    $currentPeriodCursor->addMonth();
                }
            }
        } else {
            $completedMonthsCount = count($completedMonths);
            $remainingMonths = max(0, 12 - ($completedMonthsCount + 1));
            $taxableThisMonth = $data['total_earnings'] - $untaxedComponents;
            // The month itself in full, and the months ahead without its one-off part.
            $annualTotalEarnings = $previousEarningsSum + $taxableThisMonth
                + (($taxableThisMonth - $oneOffEarnings) * $remainingMonths);
        }

        Log::debug('Corrected Annual Total Earnings: '.$annualTotalEarnings);

        // Instructor & FBR Rule: Poori Annual Total Earnings ka 10% medical cut
        $medicalExemption = $annualTotalEarnings * 0.10;
        Log::debug('10% Medical Exemption Cut: '.$medicalExemption);

        // Annual Taxable Income = Total Earnings - 10% Exemption Cut
        $annualTaxableIncome = max(0, $annualTotalEarnings - $medicalExemption);
        Log::debug('Final Annual Taxable Income: '.$annualTaxableIncome);

        // Annual tax nikalna
        $totalAnnualTax = $this->taxCalculator->annualTax($annualTaxableIncome, $fiscalYearId);

        // Ab tak pichle mahino mein kitna tax pay ho chuka hai (Current month ko chor kar)
        $previousTaxPaid = Payslip::where('employee_id', $employeeId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('month', '!=', $month)
            ->sum('withholding_tax');

        // Total saal mein kitne mahine complete ho chuke hain
        $completedMonthsCount = count($completedMonths);

        $remainingMonthsForTax = max(1, 12 - $completedMonthsCount);

        // Leftover tax jo abhi tak pay nahi hua
        $leftoverAnnualTax = max(0, $totalAnnualTax - $previousTaxPaid);

        // Monthly withholding tax = Leftover tax ko baqi rehnay walay mahino par divide karna
        $data['withholding_tax'] = round($leftoverAnnualTax / $remainingMonthsForTax, 2);
        Log::debug('Withholding Tax for this month: '.$data['withholding_tax']);

        // Total Deductions (Tax + Advances + Meal + ESI + anything added as a component)
        $data['total_deductions'] = round(
            $data['withholding_tax'] + $data['advances'] + $data['meal_deduction']
            + $data['esi_health_insurance'] + $components['deductions'],
            2
        );

        // Net Salary (Earnings + Expense Reimbursement - Deductions)
        $data['net_salary'] = round($data['total_earnings'] + $data['expense_reimbursement'] - $data['total_deductions'], 2);

        return $data;
    }

    /**
     * The pro-rating basis for this payslip, or null to pay the month in full.
     *
     * Prefers what the payslip ALREADY recorded over what the settings say today. That
     * is the whole of §4.7's rule applied to the riskiest setting in the application:
     * Payslip::booted() re-runs this calculation on every save — a clerk correcting a
     * phone number re-saves the payslip — so reading today's divisor would restate last
     * March the moment somebody changed it.
     *
     * @param  array<string, mixed>|null  $attendance
     */
    private function prorationBasisFor(string $month, ?FiscalYear $fiscalYear, ?array $attendance): ?ProrationBasis
    {
        if ($attendance === null) {
            return null;
        }

        $proration = app(AttendanceProration::class);

        // A payslip that already carries a divisor keeps it, whatever the setting now
        // says — including when the setting has since been switched off. A month that
        // was pro-rated when it was paid stays pro-rated in the record.
        $recorded = $proration->recordedBasis(
            $attendance['proration_divisor'] ?? null,
            isset($attendance['proration_basis_days']) ? (float) $attendance['proration_basis_days'] : null,
            isset($attendance['lop_days']) ? (float) $attendance['lop_days'] : null,
        );

        if ($recorded) {
            return $recorded;
        }

        return $proration->basisFor(
            isset($attendance['total_working_days']) ? (float) $attendance['total_working_days'] : null,
            isset($attendance['lop_days']) ? (float) $attendance['lop_days'] : null,
            $month,
            $fiscalYear,
        );
    }

    /**
     * Scale the components that say they should scale, and leave the rest.
     *
     * Deductions are excluded outright: a deduction never pro-rates by attendance,
     * because being away does not reduce what somebody owes.
     *
     * The taxable total is reduced alongside the gross, and that matters more than it
     * looks — the annual projection below reads the taxable figure, so reducing the
     * gross alone would leave somebody paying tax on money they were not paid.
     *
     * The query deliberately mirrors componentTotals() exactly, including the
     * `is_column_backed` filter: a component counted there and missed here (or the
     * reverse) would put the earnings total and its own parts out of step.
     *
     * @param  array{earnings: float, deductions: float, taxable_earnings: float}  $components
     * @return array{earnings: float, taxable_earnings: float}
     */
    private function prorateComponents(?EmployeeSetting $setting, ProrationBasis $basis, array $components): array
    {
        $unchanged = [
            'earnings' => $components['earnings'],
            'taxable_earnings' => $components['taxable_earnings'],
        ];

        if (! $setting) {
            return $unchanged;
        }

        $prorating = EmployeeSettingComponent::with('component')
            ->where('employee_setting_id', $setting->getKey())
            ->get()
            ->filter(fn (EmployeeSettingComponent $row): bool => $row->component
                && $row->component->is_active
                && ! $row->component->is_column_backed
                && $row->component->isEarning()
                && $row->component->prorates
                // Hours are already attendance; a lump sum is not a month's pay. Only a monthly
                // amount scales with the days paid — docs/bexio-gap-plan.md Phase 5, item 20.
                && $row->component->basis === PayComponent::BASIS_MONTHLY);

        if ($prorating->isEmpty()) {
            return $unchanged;
        }

        // Reductions are computed and subtracted rather than the totals being rebuilt,
        // so a component this method does not recognise cannot silently fall out of
        // the sum.
        $reduction = 0.0;
        $taxableReduction = 0.0;

        foreach ($prorating as $row) {
            $amount = round((float) $row->amount, 2);
            $lost = round($amount - $basis->apply($amount), 2);

            $reduction += $lost;

            if ($row->component->is_taxable) {
                $taxableReduction += $lost;
            }
        }

        return [
            'earnings' => round($components['earnings'] - $reduction, 2),
            'taxable_earnings' => round($components['taxable_earnings'] - $taxableReduction, 2),
        ];
    }

    /**
     * The month's overtime, as an amount plus the terms that produced it.
     *
     * Prefers an already-recorded rate and multiplier, for the same reason the divisor
     * does: a rate recomputed next year against a changed package or a changed work
     * pattern would restate a settled month.
     *
     * @param  array<string, mixed>|null  $attendance
     * @return array{amount: float, minutes: int, hourly_rate: float, multiplier: float}|null
     */
    private function overtimeFor($employeeId, string $month, ?FiscalYear $fiscalYear, ?array $attendance): ?array
    {
        if ($attendance === null || ! $fiscalYear || ! setting('payroll.pay_overtime')) {
            return null;
        }

        $minutes = (int) ($attendance['overtime_minutes'] ?? 0);

        if ($minutes <= 0) {
            return null;
        }

        $recordedRate = isset($attendance['overtime_hourly_rate']) ? (float) $attendance['overtime_hourly_rate'] : null;
        $recordedMultiplier = isset($attendance['overtime_multiplier']) ? (float) $attendance['overtime_multiplier'] : null;

        if ($recordedRate !== null && $recordedRate > 0 && $recordedMultiplier !== null) {
            return [
                'amount' => round($recordedRate * $recordedMultiplier * ($minutes / 60), 2),
                'minutes' => $minutes,
                'hourly_rate' => $recordedRate,
                'multiplier' => $recordedMultiplier,
            ];
        }

        $employee = Employee::find($employeeId);

        if (! $employee) {
            return null;
        }

        $pay = app(OvertimeRate::class)->amountFor($employee, $month, $fiscalYear, $minutes);

        return $pay ? [
            'amount' => $pay->amount,
            'minutes' => $pay->minutes,
            'hourly_rate' => $pay->hourlyRate,
            'multiplier' => $pay->multiplier,
        ] : null;
    }

    /**
     * The payslip as a PDF, rendered from the payslip as it stands now.
     *
     * Never from a file. A payslip is corrected after it is first printed — an
     * allowance fixed, attendance entered, an advance instalment picked up — and
     * every previous version of this served whatever was already on disk, so the
     * copy an employee downloaded went on showing figures the system had since
     * changed. There is nothing to invalidate and nothing to go stale because
     * nothing is kept.
     */
    public function renderPdf(Payslip $payslip): PdfDocument
    {
        $payslip->load('employee.user', 'fiscalYear');

        // The letterhead is the template's own business — it resolves Company Settings itself, because it is
        // also rendered directly, without this service, and must not depend on any one caller.
        return Pdf::view('pdfs.payslip', ['data' => $payslip])
            ->format('a4')
            ->margins(0, 0, 0, 0)
            ->name($this->pdfFilename($payslip));
    }

    /**
     * Names the employee, the month and the fiscal year — all three, because the
     * month was missing from the name the API used (it read a `pay_period`
     * attribute that does not exist), which collapsed every month of a fiscal
     * year onto one file per employee.
     */
    public function pdfFilename(Payslip $payslip): string
    {
        $parts = [
            $payslip->employee?->employee_id ?: 'employee-'.$payslip->employee_id,
            $payslip->month,
            $payslip->fiscalYear?->name,
        ];

        return str_replace(
            [' ', '/', '\\'],
            '-',
            implode('-', array_filter($parts)),
        ).'.pdf';
    }
}
