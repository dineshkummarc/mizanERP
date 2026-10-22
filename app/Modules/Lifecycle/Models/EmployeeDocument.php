<?php

namespace App\Modules\Lifecycle\Models;

use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A document with an expiry date.
 *
 * The expiry is the point. A visa or a driving licence that lapsed last month is the
 * kind of thing nobody notices until it matters, and the reminder machinery here is the
 * one the project certificate checks already prove out: a daily command, thresholds in
 * config, and notifications **on transitions rather than on every run**.
 */
class EmployeeDocument extends Model
{
    use Auditable;
    use StoresPlainDates;

    protected array $plainDates = ['issued_on', 'expires_on'];

    /**
     * An insurance policy is a document that expires — docs/bexio-gap-plan.md Phase 5, item 19. The
     * `number` is the policy number, `issued_on`/`expires_on` its term, and three columns of its own
     * carry what a visa has not: the insurer, the sum insured and the premium.
     */
    public const KIND_INSURANCE = 'insurance';

    /** @var array<int, string> */
    public const KINDS = ['cnic', 'passport', 'visa', 'licence', 'degree', 'contract', self::KIND_INSURANCE, 'other'];

    protected $fillable = [
        'employee_id', 'kind', 'number', 'issued_on', 'expires_on',
        'insurer', 'cover_amount', 'premium',
        'file_path', 'verified_by', 'verified_at', 'expiry_notified_at_days',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'expires_on' => 'date',
        'cover_amount' => 'decimal:2',
        'premium' => 'decimal:2',
        'verified_at' => 'datetime',
        'expiry_notified_at_days' => 'integer',
    ];

    public function isInsurance(): bool
    {
        return $this->kind === self::KIND_INSURANCE;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Documents that expire at all: many — a degree, a CNIC copy — never do. */
    public function scopeExpiring(Builder $query): Builder
    {
        return $query->whereNotNull('expires_on');
    }

    public function scopeExpiredBy(Builder $query, string|Carbon $date): Builder
    {
        return $query->expiring()->whereDate('expires_on', '<', Carbon::parse($date)->toDateString());
    }

    /**
     * Days until it lapses; negative once it has. Null when it never expires.
     *
     * Signed rather than clamped: "expired 40 days ago" is a different problem from
     * "expires in 40 days", and a caller that only knew the magnitude would treat them
     * the same.
     */
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
