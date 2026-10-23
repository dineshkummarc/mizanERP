<?php

namespace App\Support;

/**
 * Which warning threshold a deadline has newly crossed — the once-per-threshold rule.
 *
 * Lifted from Lifecycle's DocumentExpiryCheck when contact documents needed it too
 * (docs/bexio-gap-plan.md §3.7). The rule it carries: the tightest threshold the days-left figure is
 * inside is the one that counts; an expired deadline falls through to the smallest and keeps being
 * reported until somebody deals with it; a threshold already warned at, or a tighter one, is silent.
 * Crossing 60 days warns once, crossing 30 warns again, and the twenty-nine days in between say nothing.
 */
final class ExpiryThreshold
{
    /**
     * @param  array<int, int>  $thresholds  in days, any order
     * @param  int|null  $warnedAt  the threshold last warned at; null for never
     * @return int|null the threshold to warn at now, or null to stay silent
     */
    public static function newlyCrossed(int $daysLeft, array $thresholds, ?int $warnedAt): ?int
    {
        $inside = array_filter($thresholds, fn (int $threshold): bool => $daysLeft <= $threshold);

        if ($inside === []) {
            return null;
        }

        $crossed = min($inside);

        return ($warnedAt !== null && $warnedAt <= $crossed) ? null : $crossed;
    }
}
