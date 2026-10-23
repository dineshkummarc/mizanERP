<?php

namespace App\Modules\ConstructionContracts\Services;

use App\Modules\ConstructionContracts\Models\CertificateDeduction;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\ContractItem;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\ConstructionContracts\Models\ProgressClaim;
use App\Modules\ConstructionContracts\Models\ProgressClaimLine;
use App\Support\Num;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Claims in, certificates out — `docs/construction-management-plan.md` §10.
 *
 * Four rules live here, and each of them is a way a certificate goes wrong that nobody notices.
 *
 *  - **Cumulative in, movement derived.** Every figure written is "to date". A corrected certificate 6 is
 *    absorbed by certificate 7's movement rather than compounding through every later total.
 *  - **Draft computes, issue freezes.** A draft follows the schedule and the claim; an issued certificate is a
 *    statement of a moment that a third party countersigned, and nothing may restate it.
 *  - **Only agreed variations reach the certified sum.** `variations_net_to_date` reads `agreed()`, never
 *    `forecast()` — a provisionally priced variation belongs in the cost report, not in a certificate (§9).
 *  - **The previous figures are snapshotted, not joined.** Column D prints from `previous_work_value` so that
 *    voiding certificate 6 does not change what certificate 7 says — and a voided certificate is exactly when
 *    somebody reads the one after it.
 *
 * Retention movements are **not** written here. §11 makes `RetentionService` the only writer, and this service
 * produces the deduction row that service later attaches its movement to. Two write paths, one forgotten, and
 * nobody reads both registers in the same week.
 *
 * Issuing has two consequences outside this file, and both are calls rather than duplicated logic: the retention
 * movement above, and — on a subcontract — the **relief of the commitment behind it**, through
 * `CertificateCommitmentService`, which is guarded because `construction_costing` is sold separately (§5, §12).
 * Voiding runs the second one again, because the commitment target follows whichever certificate is now the
 * latest live one.
 */
class CertificationService
{
    // ------------------------------------------------------------------ claims

    /**
     * Open a claim for a period, seeded from the last one.
     *
     * Seeded rather than blank because a claim is cumulative: last month's line values are the floor for this
     * month's, and retyping four hundred of them is how a claim comes to certify less than the one before it.
     */
    public function openClaim(Contract $contract, string $periodEnd, ?string $periodStart = null): ProgressClaim
    {
        if ($contract->isDraft()) {
            throw new InvalidArgumentException(
                "{$contract->contract_number} is not executed. There is nothing to claim against yet."
            );
        }

        return TenantTransaction::run(function () use ($contract, $periodEnd, $periodStart): ProgressClaim {
            $previous = $this->latestClaim($contract);

            $claim = ProgressClaim::create([
                'contract_id' => $contract->getKey(),
                'claim_number' => $this->nextClaimNumber($contract),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);

            $seed = $previous?->lines->keyBy('contract_item_id');

            foreach ($this->claimableItems($contract) as $item) {
                $last = $seed?->get($item->getKey());

                $claim->lines()->create([
                    'contract_item_id' => $item->getKey(),
                    'measurement_input' => $last?->measurement_input ?? ProgressClaimLine::INPUT_PERCENT,
                    'cumulative_percent' => $last?->cumulative_percent,
                    'cumulative_quantity' => $last?->cumulative_quantity,
                    'cumulative_work_value' => $last?->cumulative_work_value ?? 0,
                    'cumulative_materials_value' => $last?->cumulative_materials_value ?? 0,
                ]);
            }

            return $claim->refresh();
        });
    }

    /**
     * Resolve every line's input into a value and stamp the header.
     *
     * The resolution happens here rather than being trusted from the form, because §10.2's point is that the
     * *value* is authoritative and the percent or quantity is what somebody typed — so the value has to be
     * derived from the input at a known moment, and submission is that moment.
     */
    public function submitClaim(ProgressClaim $claim): ProgressClaim
    {
        if (! $claim->isEditable()) {
            throw new InvalidArgumentException("{$claim->claim_number} is {$claim->status} and cannot be resubmitted.");
        }

        return TenantTransaction::run(function () use ($claim): ProgressClaim {
            $work = 0.0;
            $materials = 0.0;

            // Eager-loaded because `resolveWorkValue()` reads the schedule line's value and rate, and lazy
            // loading is disabled application-wide: a claim of four hundred lines would otherwise be four
            // hundred queries, which is the trap §18.3 names.
            $claim->load('lines.contractItem');

            foreach ($claim->lines as $line) {
                $resolved = $line->resolveWorkValue();
                $line->update(['cumulative_work_value' => $resolved]);

                $work += $resolved;
                $materials += (float) $line->cumulative_materials_value;
            }

            $claim->update([
                'status' => ProgressClaim::STATUS_SUBMITTED,
                'submitted_on' => $claim->submitted_on ?? now()->toDateString(),
                'submitted_by' => auth()->id(),
                'claimed_work_to_date' => round($work, 2),
                'claimed_materials_to_date' => round($materials, 2),
                // Agreed variations are in the schedule by now as their own lines, so this is the part of the
                // claim attributable to them — reported separately because the employer asks.
                'claimed_variations_to_date' => round($this->variationLinesValue($claim), 2),
                'claimed_gross_to_date' => round($work + $materials, 2),
            ]);

            return $claim->refresh();
        });
    }

    // ------------------------------------------------------------------ certificates

    /**
     * Prepare a draft certificate for a period.
     *
     * The claim is optional (§10.1): FIDIC 14.6 lets the Engineer certify without a conforming statement, so a
     * certificate can be built straight off the schedule. Where there is a claim, its values are the starting
     * point and the certifier edits down — which is the ordinary case and the reason both documents exist.
     */
    public function prepare(Contract $contract, string $periodEnd, ?ProgressClaim $claim = null): PaymentCertificate
    {
        if ($contract->isDraft()) {
            throw new InvalidArgumentException(
                "{$contract->contract_number} is not executed. Nothing may be certified against it."
            );
        }

        if ($claim !== null && $claim->contract_id !== $contract->getKey()) {
            throw new InvalidArgumentException('That claim belongs to another contract.');
        }

        return TenantTransaction::run(function () use ($contract, $periodEnd, $claim): PaymentCertificate {
            $previous = $this->latestLiveCertificate($contract);
            $sequence = (int) PaymentCertificate::query()
                ->where('contract_id', $contract->getKey())
                ->max('sequence') + 1;

            $certificate = PaymentCertificate::create([
                'contract_id' => $contract->getKey(),
                'progress_claim_id' => $claim?->getKey(),
                'certificate_number' => $contract->vocabulary()->number('certificate', $sequence),
                'sequence' => $sequence,
                'period_start' => $claim?->period_start,
                'period_end' => $periodEnd,
                // Frozen snapshots, taken now and refreshed while the certificate is a draft.
                'contract_sum_original' => $contract->contract_sum,
                // **Agreed only.** The whole of §9's rule, in one call.
                'variations_net_to_date' => $contract->agreedVariationsNet(),
                /*
                 * Two snapshots of "what came before", because two questions are being asked.
                 *
                 * `previously_certified` is net cash — the sum of earlier certificates' `current_due`. It
                 * is G702 line 7 and is what the printed form shows.
                 *
                 * `previous_gross_value_to_date` is gross. It is what the *arithmetic* nets against, and
                 * the two must not be confused: this certificate's deduction rows are movements (this
                 * period's retention, this period's advance), so netting them against a figure that has
                 * already had earlier retention removed hands that retention back. Gross against gross,
                 * movement against movement.
                 */
                'previously_certified' => $this->previouslyCertified($contract),
                'previous_gross_value_to_date' => (float) ($previous?->gross_value_to_date ?? 0),
            ]);

            $this->writeLines($certificate, $contract, $claim, $previous);
            $this->recompute($certificate->refresh());

            return $certificate->refresh();
        });
    }

    /**
     * One certificate line per claimable schedule line, with the previous figures **snapshotted**.
     *
     * A line the claim says nothing about still appears, at its previous cumulative value: a continuation sheet
     * that omitted quiet lines would not add up to the contract, and column H would have nothing to subtract
     * from.
     */
    private function writeLines(
        PaymentCertificate $certificate,
        Contract $contract,
        ?ProgressClaim $claim,
        ?PaymentCertificate $previous,
    ): void {
        $claim?->load('lines.contractItem');

        $claimed = $claim?->lines->keyBy('contract_item_id');
        $prior = $previous?->lines->keyBy('contract_item_id');

        foreach ($this->claimableItems($contract) as $item) {
            $claimLine = $claimed?->get($item->getKey());
            $priorLine = $prior?->get($item->getKey());

            $previousWork = (float) ($priorLine?->cumulative_work_value ?? 0);
            $previousMaterials = (float) ($priorLine?->cumulative_materials_value ?? 0);

            // Where the claim says nothing, the line holds what it already held. Never zero — that would
            // un-certify work already paid for.
            $work = $claimLine ? $claimLine->resolveWorkValue() : $previousWork;
            $materials = (float) ($claimLine?->cumulative_materials_value ?? $previousMaterials);

            $certificate->lines()->create([
                'contract_item_id' => $item->getKey(),
                'item_no' => $item->item_no,
                'description' => $item->description,
                'scheduled_value' => $item->scheduled_value,
                'previous_work_value' => $previousWork,
                'previous_materials_value' => $previousMaterials,
                'cumulative_work_value' => $work,
                'cumulative_materials_value' => $materials,
                'line_retention' => $this->lineRetention($contract, $item, $work, $materials),
                'measurement_input' => $claimLine?->measurement_input ?? ProgressClaimLine::INPUT_PERCENT,
                'cumulative_percent' => $claimLine?->cumulative_percent,
                'cumulative_quantity' => $claimLine?->cumulative_quantity,
            ]);
        }
    }

    /**
     * Retention on one line, cumulative.
     *
     * Materials are retained at their own rate where the contract sets one — G702 splits retainage into 5a on
     * work and 5b on stored material for exactly this reason, and a single rate would misstate both.
     */
    private function lineRetention(Contract $contract, ContractItem $item, float $work, float $materials): float
    {
        if (! $item->retention_applies) {
            return 0.0;
        }

        $workRate = (float) ($contract->retention_percent ?? 0);
        $materialsRate = (float) ($contract->materials_retention_percent ?? $contract->retention_percent ?? 0);

        return round(($work * $workRate / 100) + ($materials * $materialsRate / 100), 2);
    }

    /**
     * Recompute a draft certificate's figures and its automatic deductions.
     *
     * Called on preparation and again whenever a draft is touched. **Refused once issued**, which is the freeze:
     * the figures a third party countersigned may not be restated, and the way to correct an issued certificate
     * is to void it and issue another (or let the next one absorb the difference, which is what the cumulative
     * design is for).
     */
    public function recompute(PaymentCertificate $certificate): PaymentCertificate
    {
        if (! $certificate->isDraft()) {
            throw new InvalidArgumentException(
                "{$certificate->certificate_number} is {$certificate->status}. An issued certificate is a "
                .'statement of a moment and is not recomputed — void it, or let the next certificate absorb the '
                .'difference.'
            );
        }

        $contract = $certificate->contract;

        return TenantTransaction::run(function () use ($certificate, $contract): PaymentCertificate {
            $work = (float) $certificate->lines()->sum('cumulative_work_value');
            $materials = (float) $certificate->lines()->sum('cumulative_materials_value');
            $retentionToDate = $this->cappedRetention($contract, (float) $certificate->lines()->sum('line_retention'));

            $certificate->update([
                'gross_work_to_date' => round($work, 2),
                'gross_materials_to_date' => round($materials, 2),
                'gross_value_to_date' => round($work + $materials, 2),
                'retention_to_date' => $retentionToDate,
                'variations_net_to_date' => $contract->agreedVariationsNet(),
                'previously_certified' => $this->previouslyCertified($contract, $certificate),
                /*
                 * Refreshed alongside the net figure, and it has to be.
                 *
                 * Voiding a predecessor is the case: `test_voiding_a_certificate_is_absorbed_by_the_next
                 * _one` exists because the next draft must pick the change up, and it does that by
                 * re-reading both snapshots. Refreshing only the net one would leave this certificate
                 * netting against the gross of a certificate that no longer counts — "the whole reason for
                 * storing cumulative figures rather than movements", as that test's docblock puts it,
                 * applied to the figure the movements are measured from.
                 */
                'previous_gross_value_to_date' => (float) (
                    $this->latestLiveCertificate($contract, $certificate)?->gross_value_to_date ?? 0
                ),
            ]);

            $this->writeAutomaticDeductions($certificate->refresh());

            $certificate->refresh()->update(['current_due' => $certificate->refresh()->currentDue()]);

            return $certificate->refresh();
        });
    }

    /**
     * Retention held to date, capped.
     *
     * The limit of retention is a real contract term and the cap is why `cap_reached` exists on a retention
     * movement (§11): a movement smaller than rate × basis needs an explanation, and "the cap" is it.
     */
    private function cappedRetention(Contract $contract, float $computed): float
    {
        $cap = $contract->retentionCap();

        return round($cap === null ? $computed : min($computed, $cap), 2);
    }

    /**
     * The three deductions this module computes for itself.
     *
     * Rewritten from scratch on every recompute rather than adjusted, because an adjusted automatic row is one
     * that can be adjusted twice. Manual rows — an NCR deduction, liquidated damages, a back-charge — are left
     * exactly as somebody entered them.
     */
    private function writeAutomaticDeductions(PaymentCertificate $certificate): void
    {
        $contract = $certificate->contract;

        $certificate->deductions()->where('is_automatic', true)->delete();

        // Retention **this period**: the cumulative figure less what was already held. The header carries the
        // cumulative for G702 line 5; the row carries the movement, which is what reduces this payment.
        $previousRetention = (float) ($this->latestLiveCertificate($certificate->contract, $certificate)?->retention_to_date ?? 0);
        $retentionMovement = round((float) $certificate->retention_to_date - $previousRetention, 2);

        if ($retentionMovement != 0.0) {
            $certificate->deductions()->create([
                'kind' => CertificateDeduction::KIND_RETENTION,
                'description' => 'Retention @ '.Num::percent($contract->retention_percent),
                // Negative reduces the payment — the one convention (§10.3).
                'amount' => -1 * $retentionMovement,
                'is_automatic' => true,
            ]);
        }

        if (($advance = $this->advanceRecovery($certificate)) > 0.0) {
            $certificate->deductions()->create([
                'kind' => CertificateDeduction::KIND_ADVANCE_RECOVERY,
                'description' => 'Advance payment recovery @ '
                    .Num::percent($contract->advance_recovery_rate_pct),
                'amount' => -1 * $advance,
                'is_automatic' => true,
            ]);
        }

        /*
         * Netted against the previous GROSS, not the previous net cash — the whole of this file's
         * movement convention in one line.
         *
         * Every other row here is a movement: retention for this period, advance recovered this period.
         * Mixing a movement retention with a net previously-certified removed the earlier retention zero
         * times rather than once, and the contract over-certified by exactly that figure from the second
         * live certificate onward. Gross here keeps each period's retention removed exactly once.
         */
        if ((float) $certificate->previous_gross_value_to_date != 0.0) {
            $certificate->deductions()->create([
                'kind' => CertificateDeduction::KIND_PREVIOUS_CERTIFICATES,
                'description' => 'Less previously certified',
                'amount' => -1 * (float) $certificate->previous_gross_value_to_date,
                'is_automatic' => true,
            ]);
        }
    }

    /**
     * Advance recovery for this certificate, and zero until the trigger is passed.
     *
     * An advance is recovered at a rate out of each certificate once certified value passes a threshold, and it
     * stops when the advance is repaid — recovering more than was advanced is a deduction the contractor is
     * entitled to refuse, and it happens whenever the stop is left to a person.
     */
    private function advanceRecovery(PaymentCertificate $certificate): float
    {
        $contract = $certificate->contract;
        $advance = (float) ($contract->advance_payment_amount ?? 0);

        if ($advance <= 0.0 || $contract->advance_recovery_rate_pct === null) {
            return 0.0;
        }

        $startAt = ((float) ($contract->advance_recovery_start_pct ?? 0) / 100) * (float) $contract->contract_sum;

        if ((float) $certificate->gross_value_to_date < $startAt) {
            return 0.0;
        }

        $recoveredSoFar = (float) CertificateDeduction::query()
            ->whereIn('payment_certificate_id', PaymentCertificate::query()
                ->where('contract_id', $contract->getKey())
                ->live()
                ->whereKeyNot($certificate->getKey())
                ->select('id'))
            ->where('kind', CertificateDeduction::KIND_ADVANCE_RECOVERY)
            ->sum('amount');

        $outstanding = round($advance - abs($recoveredSoFar), 2);

        if ($outstanding <= 0.0) {
            return 0.0;
        }

        $thisPeriod = round($certificate->grossThisPeriod() * (float) $contract->advance_recovery_rate_pct / 100, 2);

        // Never more than is left owing.
        return max(0.0, min($thisPeriod, $outstanding));
    }

    /**
     * Add a deduction somebody decided on — an NCR, damages, a back-charge.
     *
     * The automatic kinds are refused here: a second retention row on one certificate is a double deduction
     * nobody notices until the other party does.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addDeduction(PaymentCertificate $certificate, array $attributes): CertificateDeduction
    {
        if (! $certificate->isDraft()) {
            throw new InvalidArgumentException(
                "{$certificate->certificate_number} is issued. A further deduction belongs on the next certificate."
            );
        }

        $kind = $attributes['kind'] ?? CertificateDeduction::KIND_OTHER;

        if (in_array($kind, CertificateDeduction::AUTOMATIC_KINDS, true)) {
            throw new InvalidArgumentException(
                "{$kind} is computed from the contract terms and is already on this certificate. Adding a second "
                .'one would deduct it twice.'
            );
        }

        $deduction = $certificate->deductions()->create($attributes + [
            'is_automatic' => false,
            'approved_by' => auth()->id(),
        ]);

        $this->recompute($certificate->refresh());

        return $deduction->refresh();
    }

    /**
     * Issue the certificate, which freezes it.
     *
     * The due date is computed from the contract's own payment terms, because the statutory regime is
     * configuration rather than code (§10.3) — FIDIC's 56 days, NEC4's shorter periods, the UK Construction
     * Act's clocks. A hardcoded statute is wrong the day it is amended and nobody notices.
     *
     * A certificate below the contract's minimum is refused rather than issued at a trivial value: that is what
     * the term means, and the value rolls into the next certificate by construction, since everything is
     * cumulative.
     */
    public function issue(PaymentCertificate $certificate, ?string $issuedOn = null): PaymentCertificate
    {
        if (! $certificate->isDraft()) {
            throw new InvalidArgumentException("{$certificate->certificate_number} is already {$certificate->status}.");
        }

        $this->recompute($certificate);
        $certificate->refresh();

        $contract = $certificate->contract;
        $minimum = (float) ($contract->minimum_certificate_amount ?? 0);
        $due = $certificate->currentDue();

        /*
         * **Compliance blocks certification, here rather than in a form** — §12.
         *
         * A rule enforced only in a Filament form is one that a queue job, a console command or any future API bypasses
         * in complete silence, and this is the rule that stops a subcontractor being paid for work they had no
         * insurance to be doing.
         *
         * At *certification* rather than at payment, which is §12's argument: blocking payment leaves "an approved
         * payable in the ledger that finance cannot pay", and that is worse than a refusal because the liability
         * already exists and the stuck payment has nobody's name on it.
         *
         * Judged as at the certificate's valuation date, so a June payment is judged on June's cover.
         */
        $blockers = app(ComplianceService::class)->blockers($contract, $certificate->period_end);

        if ($blockers !== [] && $certificate->compliance_override_at === null) {
            throw new InvalidArgumentException(
                "{$certificate->certificate_number} cannot be certified: ".implode('; ', $blockers)
                .'. Either the document is produced, or somebody with the override permission certifies anyway and '
                .'says why — which is recorded against this certificate.'
            );
        }

        if ($minimum > 0.0 && $due > 0.0 && $due < $minimum) {
            throw new InvalidArgumentException(
                'The amount due of '.number_format($due, 2).' is below this contract\'s minimum certificate '
                .'amount of '.number_format($minimum, 2).'. The value stays in the schedule and rolls into the '
                .'next certificate.'
            );
        }

        return TenantTransaction::run(function () use ($certificate, $issuedOn, $contract, $due): PaymentCertificate {
            $issued = Carbon::parse($issuedOn ?? now()->toDateString());

            $certificate->update([
                'status' => PaymentCertificate::STATUS_ISSUED,
                'issued_on' => $issued->toDateString(),
                'due_on' => $contract->payment_terms_days === null
                    ? null
                    : $issued->copy()->addDays($contract->payment_terms_days)->toDateString(),
                // The figure the third party countersigns. Written last, and never again.
                'current_due' => $due,
                'certified_by' => auth()->id(),
            ]);

            $certificate->progressClaim?->update(['status' => ProgressClaim::STATUS_CERTIFIED]);

            /*
             * The retention movement is written **by `RetentionService`**, called here.
             *
             * §11 makes that service the only writer, and this is the trigger rather than an exception to it: the
             * money is held at the moment a certificate is issued, and the two registers are linked through the
             * deduction row so the reconciliation can compare them line by line.
             */
            app(RetentionService::class)->recordFromCertificate($certificate->refresh());

            /*
             * And on the payable side, the commitment behind the subcontract is relieved — §5's "earlier of receipt
             * or certificate", Phase 6c. Guarded inside that service rather than here, so a company with no cost
             * control issues certificates exactly as it did before, and so the licence question is asked in one
             * place. Nothing happens on the receivable side: money coming in was never committed to anybody.
             */
            app(CertificateCommitmentService::class)->syncFor($contract, $issued->toDateString());

            return $certificate->refresh();
        });
    }

    /**
     * Void an issued certificate, with a reason.
     *
     * The row and its number stay: the numbering has no gaps because a missing certificate number is a question
     * at adjudication (§8.3), and "IPC-6 was voided on the 14th" is an answer while a hole in the series is not.
     *
     * The next certificate needs no adjustment — every figure is cumulative, so a voided certificate simply
     * stops counting towards `previously_certified` and the next one's movement absorbs the difference.
     */
    public function void(PaymentCertificate $certificate, string $reason): PaymentCertificate
    {
        if ($certificate->status === PaymentCertificate::STATUS_VOID) {
            throw new InvalidArgumentException("{$certificate->certificate_number} is already void.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Voiding a certificate needs a reason. Somebody outside this company has a copy.');
        }

        return TenantTransaction::run(function () use ($certificate, $reason): PaymentCertificate {
            $certificate->update([
                'status' => PaymentCertificate::STATUS_VOID,
                'void_reason' => $reason,
            ]);

            /*
             * The commitment follows the void, and the arithmetic is why this is one call rather than a reversal of
             * what this certificate relieved: the target is the cumulative figure on whatever certificate is now the
             * latest live one. So voiding the last certificate gives commitment back, and voiding an earlier one
             * while a later one stands moves nothing — which is right, and is the case a "reverse its own relief"
             * design gets backwards.
             *
             * Dated today rather than on the certificate's issue date: the money became uncommitted when somebody
             * voided it, and back-dating the movement would restate a month that has been reported on.
             *
             * The contract is fetched by key rather than read off the relation, because a certificate voided from the
             * register is a row straight out of the table with nothing loaded on it, and lazy loading is disabled.
             */
            app(CertificateCommitmentService::class)->syncFor(
                Contract::query()->findOrFail($certificate->contract_id),
                now()->toDateString(),
            );

            return $certificate->refresh();
        });
    }

    // ------------------------------------------------------------------ shared reads

    /**
     * Net cash certified by every live certificate before this one.
     *
     * The figure this period's payment is netted against, and the reason a voided certificate needs no
     * adjustment anywhere: it drops out of this sum and the arithmetic closes itself.
     */
    private function previouslyCertified(Contract $contract, ?PaymentCertificate $excluding = null): float
    {
        return round((float) PaymentCertificate::query()
            ->where('contract_id', $contract->getKey())
            ->live()
            ->when($excluding, fn ($q) => $q->whereKeyNot($excluding->getKey()))
            ->when($excluding, fn ($q) => $q->where('sequence', '<', $excluding->sequence))
            ->sum('current_due'), 2);
    }

    /** The last issued certificate, which is where the frozen `previous_*` figures come from. */
    private function latestLiveCertificate(Contract $contract, ?PaymentCertificate $before = null): ?PaymentCertificate
    {
        return PaymentCertificate::query()
            ->with('lines')
            ->where('contract_id', $contract->getKey())
            ->live()
            ->when($before, fn ($q) => $q->where('sequence', '<', $before->sequence))
            ->orderByDesc('sequence')
            ->first();
    }

    private function latestClaim(Contract $contract): ?ProgressClaim
    {
        return ProgressClaim::query()
            ->with('lines')
            ->where('contract_id', $contract->getKey())
            ->whereNot('status', ProgressClaim::STATUS_REJECTED)
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->first();
    }

    public function nextClaimNumber(Contract $contract): string
    {
        $used = ProgressClaim::query()
            ->where('contract_id', $contract->getKey())
            ->pluck('claim_number')
            ->map(fn (string $number): int => (int) (preg_match('/(\d+)$/', $number, $m) ? $m[1] : 0))
            ->filter()
            ->max() ?? 0;

        return $contract->vocabulary()->number('claim', $used + 1);
    }

    /**
     * The claimable lines of a schedule: active, and not a heading with lines under it.
     *
     * Headings are excluded for the same reason a heading cost code cannot take cost — they would be counted
     * twice, once in themselves and once in their children.
     *
     * @return \Illuminate\Support\Collection<int, ContractItem>
     */
    private function claimableItems(Contract $contract): \Illuminate\Support\Collection
    {
        return ContractItem::query()
            ->where('contract_id', $contract->getKey())
            ->claimable()
            ->orderBy('sort')
            ->orderBy('item_no')
            ->get();
    }

    /** How much of a claim is attributable to lines a variation wrote. */
    private function variationLinesValue(ProgressClaim $claim): float
    {
        return (float) $claim->lines()
            ->whereHas('contractItem', fn ($query) => $query->whereNotNull('source_variation_id'))
            ->sum('cumulative_work_value');
    }
}
