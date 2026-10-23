<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * A document with an `expires_on` that may be null.
 *
 * Lifted from EmployeeDocument when contact documents needed the same four answers
 * (docs/bexio-gap-plan.md §3.7). `daysUntilExpiry()` is signed rather than clamped: "expired 40 days
 * ago" is a different problem from "expires in 40 days", and a caller that only knew the magnitude would
 * treat them the same.
 */
trait ExpiresOn
{
    /** Documents that expire at all: many — a degree, a CNIC copy — never do. */
    public function scopeExpiring(Builder $query): Builder
    {
        return $query->whereNotNull('expires_on');
    }

    public function scopeExpiredBy(Builder $query, string|Carbon $date): Builder
    {
        return $query->expiring()->whereDate('expires_on', '<', Carbon::parse($date)->toDateString());
    }

    /** Days until it lapses; negative once it has. Null when it never expires. */
    public function daysUntilExpiry(string|Carbon|null $asOf = null): ?int
    {
        if (! $this->expires_on) {
            return null;
        }

        $asOf = $asOf ? Carbon::parse($asOf) : now();

        return (int) $asOf->startOfDay()->diffInDays($this->expires_on->copy()->startOfDay(), false);
    }

    public function hasExpired(string|Carbon|null $asOf = null): bool
    {
        $days = $this->daysUntilExpiry($asOf);

        return $days !== null && $days < 0;
    }
}
