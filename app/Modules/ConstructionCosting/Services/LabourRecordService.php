<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\LabourRecord;
use App\Modules\ConstructionCosting\Models\Trade;
use App\Modules\ConstructionCosting\Models\Worker;
use App\Modules\ConstructionCosting\Support\ResolvedLabourRate;
use App\Support\Num;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Site sheets in, job cost out — `docs/construction-management-plan.md` §7.1 and §7.3.
 *
 * Four rules live here rather than in a form, because the site sheet, the timesheet import of §7.1 and any future API
 * are three callers and a rule kept in one of them is a rule the other two walk past.
 *
 *  - **Approval snapshots the rate, and nothing recomputes afterwards.** §7.2's dated table stops a wage revision
 *    restating history; this snapshot is what holds when somebody edits the rate row itself. `labour_rate_id` records
 *    which row it came from, so the figure can be traced rather than trusted.
 *  - **Two entries, never one** (§7.3). Labour, and burden as its own entry against the same cost code flagged
 *    `is_burden`. "Labour cost" stays one number and burden stays separable, and neither has to be backed out of the
 *    other by a report.
 *  - **No rate means refusal, not zero.** An hour with no rate cannot be costed, and a week of labour at 0.00 is
 *    §18.1's healthy-looking figure hiding an absence — the one failure mode this whole section is arranged around.
 *  - **A correction is a reversal.** §3.3's rule: once cost is booked the record keeps its row, both entries are
 *    reversed as a pair, and the reason is on the record.
 *
 * **Burden is `pending`, not `memo`**, and that was a contradiction inside the plan until Phase 7b resolved it: §7.3
 * requires burden charged to jobs to credit Labour Burden Absorbed, so it has a GL side and §11's posting service is
 * what writes it. See `CostEntry::GL_MEMO`, whose comment named burden as an example and was the error.
 */
class LabourRecordService
{
    /** A day is the ceiling. Anything beyond it is a double entry, not a long shift. */
    private const MINUTES_IN_A_DAY = 1440;

    public function __construct(private readonly CostLedger $ledger, private readonly LabourRateService $rates) {}

    /**
     * Record a day's work as a draft.
     *
     * A draft rather than immediate cost, following every other document in this suite: a site sheet is typed by
     * whoever collected it and read by somebody else before it becomes money.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(Worker $worker, Job $job, CostCode $code, array $attributes): LabourRecord
    {
        $workedOn = Carbon::parse($attributes['worked_on'] ?? now())->toDateString();

        $normal = (int) ($attributes['normal_minutes'] ?? 0);
        $overtime = (int) ($attributes['overtime_minutes'] ?? 0);

        $this->guardCode($code);
        $this->guardMinutes($normal, $overtime);
        $this->guardEngagement($worker, $workedOn);
        $this->guardDayLength($worker, $workedOn, $normal + $overtime);

        // `array_merge` rather than `+`, so the normalised values below win: with `+` the raw attribute would
        // survive and an unparsed `worked_on` or a string `normal_minutes` would reach the column unchecked.
        return TenantTransaction::run(fn (): LabourRecord => LabourRecord::create(array_merge($attributes, [
            'worker_id' => $worker->getKey(),
            'job_id' => $job->getKey(),
            'cost_code_id' => $code->getKey(),
            'worked_on' => $workedOn,
            'normal_minutes' => $normal,
            'overtime_minutes' => $overtime,
            // The trade worked *as*, defaulting to the worker's own. A mason labouring for a day is costed as a
            // labourer, so the rate ladder has to be asked about the work rather than about the person.
            'trade_id' => $attributes['trade_id'] ?? $worker->trade_id,
        ])));
    }

    /**
     * Approve a record, which is what books the cost.
     *
     * The rate is resolved **as at the day worked**, never as at today: a sheet approved in September for August work
     * is costed at August's rate, which is the whole reason the rate table is dated. Approving is also the moment the
     * snapshot is taken, so the two facts are one act and cannot drift apart.
     */
    public function approve(LabourRecord $record): LabourRecord
    {
        if (! $record->isDraft()) {
            throw new InvalidArgumentException(
                "That record is {$record->status} and cannot be approved again. A correction to approved labour is a "
                .'reversal, which keeps both the original and the reason.'
            );
        }

        // Loaded explicitly: a record approved from the register is a row straight out of the table with no
        // relations on it, and lazy loading is disabled application-wide. Phase 6c found the same shape in
        // `CommitmentService::relieve()` the hard way.
        $record->loadMissing(['worker', 'job', 'costCode']);

        $worker = $record->worker;
        $workedOn = $record->worked_on->toDateString();

        $rate = $this->rates->resolve(
            job: $record->job,
            trade: $record->trade_id ? Trade::query()->find($record->trade_id) : null,
            worker: $worker,
            on: $workedOn,
        );

        if ($rate === null) {
            throw new InvalidArgumentException(
                'No labour rate applies to '.($worker?->displayName() ?? 'that worker')." on {$workedOn}, so this "
                .'day cannot be costed. Set at least a company default rate — nothing here guesses one, because a '
                .'week of labour costing 0.00 looks like a healthy figure and is not.'
            );
        }

        $labour = $rate->costOf((int) $record->normal_minutes, (int) $record->overtime_minutes);
        $burden = $rate->burdenOn($labour);

        return TenantTransaction::run(function () use ($record, $rate, $labour, $burden): LabourRecord {
            $record->update([
                // **The snapshot** (§7.1), written once and never recomputed.
                'labour_rate_id' => $rate->rateId,
                'cost_rate_per_hour' => $rate->costRatePerHour,
                'overtime_multiplier' => $rate->overtimeMultiplier,
                'burden_percent' => $rate->burdenPercent,
                'labour_amount' => $labour,
                'burden_amount' => $burden,
                'status' => LabourRecord::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by' => auth()->id(),
            ]);

            $record->refresh();

            $entry = $this->writeLabourEntry($record, $rate, $labour);
            $burdenEntry = $burden == 0.0 ? null : $this->writeBurdenEntry($record, $burden);

            $record->update([
                'cost_entry_id' => $entry->getKey(),
                'burden_entry_id' => $burdenEntry?->getKey(),
            ]);

            return $record->refresh();
        });
    }

    /**
     * The labour half.
     *
     * The quantity is **hours**, because §3.1's whole argument for this ledger is rate analysis — "we budgeted 4,200
     * per m³ and we are running at 5,050" — and a labour entry whose quantity was minutes would report a unit rate in
     * cost-per-minute, which nobody prices anything in.
     */
    private function writeLabourEntry(LabourRecord $record, ResolvedLabourRate $rate, float $amount): CostEntry
    {
        $hours = round($record->totalMinutes() / 60, 4);

        return $this->ledger->record($record->job, $record->costCode, [
            'kind' => CostEntry::KIND_ACTUAL,
            'gl_treatment' => $this->treatmentFor($record),
            /*
             * The credit this labour owes, and it is null where the payslip already posted it.
             *
             * §4.1's table: an employee's time reaches the books through payroll, so job cost mirrors a GL cost that
             * exists and owes nothing. Anybody paid outside the payroll has no GL document at all, so construction owes
             * both sides and the credit is a liability — a gang paid next Friday is money the company owes today.
             */
            'gl_purpose' => $this->treatmentFor($record) === CostEntry::GL_PENDING ? 'site_labour' : null,
            'amount' => $amount,
            'quantity' => $hours,
            'unit_of_measure' => 'hr',
            // The blended rate the day actually cost, which is not the rate on the row when there was overtime —
            // and the blended one is what a rate comparison has to read.
            'unit_rate' => $hours > 0.0 ? round($amount / $hours, 4) : null,
            'incurred_on' => $record->worked_on->toDateString(),
            'wbs_node_id' => $record->wbs_node_id,
            'worker_id' => $record->worker_id,
            'employee_id' => $record->worker?->employee_id,
            'description' => $record->description ?: $record->displayName(),
            'source_type' => $record::class,
            'source_id' => $record->getKey(),
        ]);
    }

    /**
     * The burden half — **its own entry, against the same cost code** (§7.3).
     *
     * One entry with the burden rolled in would make "labour cost" and "labour cost plus burden" the same number, so
     * neither could be reported; two entries with the flag make both available from one sum and a filter. The rate is
     * the burden percentage rather than a money-per-hour, because that is the figure somebody set.
     */
    private function writeBurdenEntry(LabourRecord $record, float $amount): CostEntry
    {
        return $this->ledger->record($record->job, $record->costCode, [
            'kind' => CostEntry::KIND_ACTUAL,
            // Pending, not memo: §7.3 requires this to credit Labour Burden Absorbed, and §11 posts it. Charge it and
            // never absorb it and job cost exceeds GL cost by exactly this figure, growing every month.
            'gl_treatment' => CostEntry::GL_PENDING,
            // Which credit it owes, named at the point of writing rather than inferred at the point of posting — §4.1.
            'gl_purpose' => 'labour_burden',
            'amount' => $amount,
            'is_burden' => true,
            'incurred_on' => $record->worked_on->toDateString(),
            'wbs_node_id' => $record->wbs_node_id,
            'worker_id' => $record->worker_id,
            'employee_id' => $record->worker?->employee_id,
            'description' => 'Burden @ '.Num::percent($record->burden_percent).' on '
                .$record->displayName(),
            'source_type' => $record::class,
            'source_id' => $record->getKey(),
        ]);
    }

    /**
     * Who owes the general ledger the labour posting — §4.1's table, applied to one person.
     *
     * **An employee's time already reaches the GL through the payslip**, so job cost is a *dimension* of a cost the
     * books already have and this mirrors it. Posting it again would double the company's labour cost. Anybody paid
     * outside the payroll — daily-wage, casual, gang-supplied — has no GL document behind them at all, so construction
     * owes the posting and §11's service makes it.
     *
     * Guarded on Payroll being licensed, because "the payslip posted it" is only true where there are payslips. Without
     * that module an employee's site labour is in exactly the position a daily-wage hand's is, and claiming otherwise
     * would leave a cost §4 could never find a GL side for.
     *
     * The figures will not match — rate × hours is not the payslip — and that difference is real. §18.1 says so
     * plainly: without Payroll "burden is charged and never absorbed, and §4 reports the gap in words rather than
     * balancing". This method decides who posts, not who is right.
     */
    private function treatmentFor(LabourRecord $record): string
    {
        $isEmployee = $record->worker?->engagement === Worker::ENGAGEMENT_EMPLOYEE
            && $record->worker?->employee_id !== null;

        return $isEmployee && modules()->enabled('payroll')
            ? CostEntry::GL_MIRRORED
            : CostEntry::GL_PENDING;
    }

    /**
     * Reverse an approved record: both entries, as a pair, with a reason.
     *
     * Both or neither — a reversal that backed out the labour and left the burden would leave a job carrying burden on
     * work it is no longer charged for, which is a figure nobody would think to look for.
     */
    public function reverse(LabourRecord $record, string $reason): LabourRecord
    {
        if (! $record->isApproved()) {
            throw new InvalidArgumentException(
                "That record is {$record->status}. Only approved labour has cost to reverse — a draft is deleted or "
                .'corrected in place.'
            );
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Reversing booked labour needs a reason. The cost report will show both rows, and "why" is the only '
                .'thing that explains the pair.'
            );
        }

        $record->loadMissing(['costEntry', 'burdenEntry']);

        return TenantTransaction::run(function () use ($record, $reason): LabourRecord {
            foreach ([$record->costEntry, $record->burdenEntry] as $entry) {
                if ($entry !== null && ! $entry->isReversed()) {
                    $this->ledger->reverse($entry, $reason);
                }
            }

            $record->update([
                'status' => LabourRecord::STATUS_REVERSED,
                'reversed_at' => now(),
                'reversed_by' => auth()->id(),
                'reversal_reason' => $reason,
            ]);

            return $record->refresh();
        });
    }

    /**
     * Update a draft in place.
     *
     * Allowed only while it is a draft, which is where §3.3 draws the line: "forcing a reversal pair for a typo made
     * ten seconds ago produces three rows where one is true".
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(LabourRecord $record, array $attributes): LabourRecord
    {
        if (! $record->isDraft()) {
            throw new InvalidArgumentException(
                "That record is {$record->status} and its cost is booked. Reverse it and record the day again."
            );
        }

        $record->loadMissing('worker');

        $normal = (int) ($attributes['normal_minutes'] ?? $record->normal_minutes);
        $overtime = (int) ($attributes['overtime_minutes'] ?? $record->overtime_minutes);

        $this->guardMinutes($normal, $overtime);
        $this->guardDayLength(
            $record->worker,
            $record->worked_on->toDateString(),
            $normal + $overtime,
            excluding: $record->getKey(),
        );

        $record->update($attributes);

        return $record->refresh();
    }

    // ------------------------------------------------------------------ the guards

    private function guardCode(CostCode $code): void
    {
        // The same two refusals `CostLedger::record()` makes, made here so a *draft* cannot be built against a code
        // that could never be approved — a queue of drafts nobody can approve is worse than a refusal at entry.
        if (! $code->is_leaf) {
            throw new InvalidArgumentException(
                "Cost code {$code->code} is a heading. Booking labour against it would double-count in every "
                .'rolled-up total.'
            );
        }

        if (! $code->is_active) {
            throw new InvalidArgumentException("Cost code {$code->code} is switched off and cannot take new cost.");
        }
    }

    private function guardMinutes(int $normal, int $overtime): void
    {
        if ($normal < 0 || $overtime < 0) {
            throw new InvalidArgumentException('Negative time is not a correction. Reverse the record instead.');
        }

        if ($normal + $overtime <= 0) {
            throw new InvalidArgumentException(
                'A record of no time tells nobody anything, and it would book a cost of zero against the job.'
            );
        }
    }

    /**
     * Somebody who had not started, or had already left, cannot have worked.
     *
     * Read from the dates rather than from `is_active`, because a sheet for March is entered in April and the flag
     * only ever answers about today — §7.1's reason for having both.
     */
    private function guardEngagement(Worker $worker, string $workedOn): void
    {
        if (! $worker->wasEngagedOn($workedOn)) {
            throw new InvalidArgumentException(
                "{$worker->displayName()} was not engaged on {$workedOn}. Correct the start or leaving date, or the "
                .'day on the sheet — a day booked outside somebody\'s engagement is usually the wrong person.'
            );
        }
    }

    /**
     * One person cannot work more than a day in a day.
     *
     * **The duplicate-sheet guard**, and it is deliberately a ceiling on the total rather than a uniqueness rule: two
     * records for one worker on one day are ordinary — morning on formwork, afternoon on steel — so a unique index
     * would refuse the normal case while catching nothing. What is never ordinary is the same sheet entered twice, and
     * that shows up as a day of more than twenty-four hours.
     */
    private function guardDayLength(?Worker $worker, string $workedOn, int $minutes, int|string|null $excluding = null): void
    {
        if ($worker === null) {
            return;
        }

        // Fetched and summed in PHP rather than through a raw expression: it is one worker's rows for one day, so
        // the set is tiny, and `sum(DB::raw(...))` over two columns is the kind of query that quietly stops being
        // portable.
        $already = (int) LabourRecord::query()
            ->where('worker_id', $worker->getKey())
            ->workedOn($workedOn)
            ->whereNot('status', LabourRecord::STATUS_REVERSED)
            ->when($excluding, fn ($q) => $q->whereKeyNot($excluding))
            ->get(['id', 'normal_minutes', 'overtime_minutes'])
            ->sum(fn (LabourRecord $row): int => (int) $row->normal_minutes + (int) $row->overtime_minutes);

        if ($already + $minutes > self::MINUTES_IN_A_DAY) {
            throw new InvalidArgumentException(
                "{$worker->displayName()} would be booked "
                .round(($already + $minutes) / 60, 1)
                ." hours on {$workedOn}, which is more than a day. That is almost always the same sheet entered "
                .'twice — check what is already recorded for that date before adding more.'
            );
        }
    }
}
