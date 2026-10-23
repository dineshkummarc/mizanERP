<?php

namespace App\Modules\Lifecycle\Services;

use App\Modules\Lifecycle\Models\EmployeeDocument;
use App\Support\ExpiryThreshold;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which documents are about to lapse, and which of those anybody has been told about.
 *
 * **Notifies on transitions, never on every run.** The health-check alerts taught that
 * lesson here already: a daily job that mails the same person the same warning for
 * thirty days trains them to filter it, and then the one that mattered is filtered too.
 *
 * `expiry_notified_at_days` records the threshold last warned at, so crossing 60 days
 * warns once, crossing 30 warns again, and the twenty-nine days in between are silent.
 */
class DocumentExpiryCheck
{
    /**
     * Documents that have newly crossed a threshold.
     *
     * @return Collection<int, array{document: EmployeeDocument, days: int, threshold: int}>
     */
    public function due(string|Carbon|null $asOf = null): Collection
    {
        $asOf = $asOf ? Carbon::parse($asOf) : now();
        $thresholds = $this->thresholds();

        return EmployeeDocument::query()
            ->expiring()
            ->with('employee')
            ->get()
            ->map(function (EmployeeDocument $document) use ($asOf, $thresholds): ?array {
                $days = $document->daysUntilExpiry($asOf);

                if ($days === null) {
                    return null;
                }

                // The once-per-threshold rule, shared with contact documents — App\Support\ExpiryThreshold.
                $crossed = ExpiryThreshold::newlyCrossed($days, $thresholds, $document->expiry_notified_at_days);

                return $crossed === null ? null : ['document' => $document, 'days' => $days, 'threshold' => $crossed];
            })
            ->filter()
            ->values();
    }

    /**
     * Everything expiring inside a window, whether anybody has been warned about it or not.
     *
     * **The listing, as against `due()`'s notification query, and the distinction is the whole reason this
     * method exists.** `due()` deliberately suppresses a document once its threshold has been warned at —
     * which is right for a daily mail and wrong for a report. Built on `due()`, *Documents Expiring* would
     * have shown fewer documents the more reliably the reminders went out, and been emptiest on the
     * company that had been most diligent. Nobody reading it would have known.
     *
     * Expired documents are included and sort first. An expired visa is not a warning that stops being
     * true — `due()` says so about its own smallest threshold, and a listing has even less excuse to drop
     * one.
     *
     * The window defaults to the widest configured threshold, so the report covers exactly the population
     * the reminders watch: a company that widens `lifecycle.document_expiry_thresholds` widens both at once
     * rather than having a report that disagrees with its own mail.
     *
     * @return Collection<int, array{document: EmployeeDocument, days: int, bucket: string}>
     */
    public function expiring(string|Carbon|null $asOf = null, ?int $withinDays = null): Collection
    {
        $asOf = $asOf ? Carbon::parse($asOf) : now();
        $thresholds = $this->thresholds();
        $within = $withinDays ?? ($thresholds[0] ?? 60);

        return EmployeeDocument::query()
            ->expiring()
            ->with('employee.user')
            ->get()
            ->map(function (EmployeeDocument $document) use ($asOf, $within, $thresholds): ?array {
                $days = $document->daysUntilExpiry($asOf);

                if ($days === null || $days > $within) {
                    return null;
                }

                return [
                    'document' => $document,
                    'days' => $days,
                    'bucket' => $this->bucket($days, $thresholds),
                ];
            })
            ->filter()
            // Soonest first, so the top of the list is the most urgent — and the already-expired, whose
            // day count is negative, are above everything.
            ->sortBy('days')
            ->values();
    }

    /**
     * Which band a document falls in, named in the words the thresholds are set in.
     *
     * The bands are the configured thresholds rather than a fixed set, so a company that warns at 90 days
     * sees a 90-day band. Naming them off `config()` is what keeps the report and the reminders describing
     * one thing.
     *
     * @param  array<int, int>  $thresholds  widest first
     */
    private function bucket(int $days, array $thresholds): string
    {
        if ($days < 0) {
            return 'Expired';
        }

        foreach (array_reverse($thresholds) as $threshold) {
            if ($days <= $threshold) {
                return 'Within '.$threshold.' days';
            }
        }

        return 'Later';
    }

    /** Record that a threshold has been warned about, so the next run is silent. */
    public function markNotified(EmployeeDocument $document, int $threshold): void
    {
        $document->update(['expiry_notified_at_days' => $threshold]);
    }

    /**
     * Reset the warning state when an expiry moves.
     *
     * A renewed passport is a new deadline, and it has to be able to warn again — the
     * transition rule would otherwise silence the document for ever after one warning.
     */
    public function resetOnRenewal(EmployeeDocument $document): void
    {
        $document->update(['expiry_notified_at_days' => null]);
    }

    /** @return array<int, int> descending, so the loop above lands on the tightest crossed */
    private function thresholds(): array
    {
        $thresholds = (array) config('lifecycle.document_expiry_thresholds', [60, 30, 7]);

        rsort($thresholds);

        return array_map('intval', $thresholds);
    }
}
