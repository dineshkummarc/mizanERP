<?php

namespace App\Modules\Invoicing\Services;

use App\Modules\Invoicing\Models\ContactDocument;
use App\Support\ExpiryThreshold;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which contact documents have newly crossed a warning threshold — docs/bexio-gap-plan.md §3.7.
 *
 * Lifecycle's DocumentExpiryCheck for the other kind of document, sharing its rule through
 * App\Support\ExpiryThreshold: warn once per threshold crossed, never once per day, and keep reporting an
 * expired document until somebody deals with it.
 */
class ContactDocumentExpiry
{
    /** @return Collection<int, array{document: ContactDocument, days: int, threshold: int}> */
    public function due(string|Carbon|null $asOf = null): Collection
    {
        $asOf = $asOf ? Carbon::parse($asOf) : now();
        $thresholds = $this->thresholds();

        return ContactDocument::query()
            ->expiring()
            ->with('contact')
            ->get()
            ->map(function (ContactDocument $document) use ($asOf, $thresholds): ?array {
                $days = $document->daysUntilExpiry($asOf);
                $crossed = $days === null
                    ? null
                    : ExpiryThreshold::newlyCrossed($days, $thresholds, $document->expiry_notified_at_days);

                return $crossed === null ? null : ['document' => $document, 'days' => $days, 'threshold' => $crossed];
            })
            ->filter()
            ->values();
    }

    /** Record that a threshold has been warned about, so the next run is silent. */
    public function markNotified(ContactDocument $document, int $threshold): void
    {
        $document->update(['expiry_notified_at_days' => $threshold]);
    }

    /** @return array<int, int> */
    public function thresholds(): array
    {
        return array_map('intval', (array) config('invoicing.contact_document_expiry_thresholds', [60, 30, 7]));
    }
}
