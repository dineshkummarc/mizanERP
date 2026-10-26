<?php

namespace App\Modules\Projects\Models;

use App\Models\TenantModel as Model;
use App\Support\TenantTransaction;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;

/**
 * One deployment target of a project (prod/qual/dev): its URL, shared login
 * details, and the health-check state driven by CheckEnvironmentHealth.
 */
class ProjectEnvironment extends Model
{
    use Auditable;

    public const KIND_PROD = 'prod';

    public const KIND_QUAL = 'qual';

    public const KIND_DEV = 'dev';

    public const KINDS = [
        self::KIND_PROD => 'Production',
        self::KIND_QUAL => 'Qualification',
        self::KIND_DEV => 'Development',
    ];

    public const HEALTH_UP = 'up';

    public const HEALTH_DOWN = 'down';

    public const HEALTH_UNKNOWN = 'unknown';

    protected $fillable = [
        'kind', 'url', 'username', 'password', 'notes',
        'is_monitored', 'alerts_enabled', 'muted_until', 'check_interval_min',
        'expected_content', 'expected_status', 'is_public',
    ];

    /**
     * Mirrors the column defaults so a freshly created instance answers
     * isMonitorable()/alertsActive() correctly without a refresh().
     */
    protected $attributes = [
        'is_monitored' => true,
        'alerts_enabled' => true,
        'is_public' => false,
        'consecutive_failures' => 0,
        'consecutive_successes' => 0,
    ];

    protected $casts = [
        'is_monitored' => 'boolean',
        'alerts_enabled' => 'boolean',
        'is_public' => 'boolean',
        'muted_until' => 'datetime',
        'health_checked_at' => 'datetime',
        'ssl_expires_at' => 'datetime',
        'ssl_checked_at' => 'datetime',
        'ssl_valid_chain' => 'boolean',
        // Encrypted at rest — security audit finding 14. The activity-log exclusion below still applies.
        'password' => 'encrypted',
    ];

    /**
     * Credentials must never reach the activity log: the value is plain text by
     * decision, and logging it would keep a permanent history of every rotated
     * password. The health_* columns are excluded because a check every few
     * minutes would otherwise bury the log in noise.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept([
                'password',
                'health_status', 'health_code', 'health_latency_ms', 'health_error', 'health_checked_at',
                'consecutive_failures', 'consecutive_successes',
                'ssl_expires_at', 'ssl_issuer', 'ssl_checked_at', 'ssl_valid_chain', 'ssl_alerted_at_days',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName(class_basename(static::class));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(ProjectEnvironmentCheck::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(ProjectEnvironmentIncident::class);
    }

    public function openIncident(): ?ProjectEnvironmentIncident
    {
        return $this->incidents()->whereNull('resolved_at')->latest('started_at')->first();
    }

    public function label(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst((string) $this->kind);
    }

    public function isMonitorable(): bool
    {
        return $this->is_monitored && filled($this->url);
    }

    public function scopeMonitorable($query)
    {
        return $query->where('is_monitored', true)->whereNotNull('url')->where('url', '!=', '');
    }

    public function checkIntervalMinutes(): int
    {
        return (int) ($this->check_interval_min ?: config('projects.health.default_interval', 5));
    }

    /**
     * Due for its next check? Evaluated in PHP rather than SQL because the
     * per-environment interval would need database-specific date arithmetic,
     * and the row count here is tens per company, not millions.
     */
    public function isDue(?Carbon $now = null): bool
    {
        if (! $this->isMonitorable()) {
            return false;
        }

        if (! $this->health_checked_at) {
            return true;
        }

        return $this->health_checked_at->lte(($now ?? now())->copy()->subMinutes($this->checkIntervalMinutes()));
    }

    /** All monitorable environments of the current tenant that are due now. */
    public static function dueForCheck(?Carbon $now = null): EloquentCollection
    {
        return static::query()->monitorable()->get()->filter(fn (self $env) => $env->isDue($now))->values();
    }

    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }

    public function alertsActive(): bool
    {
        return (bool) config('projects.alerts.enabled', true)
            && $this->alerts_enabled
            && ! $this->isMuted();
    }

    public function isHttps(): bool
    {
        return str_starts_with(strtolower((string) $this->url), 'https://');
    }

    public function sslDaysRemaining(): ?int
    {
        return $this->ssl_expires_at ? now()->diffInDays($this->ssl_expires_at, false) : null;
    }

    /**
     * Uptime over a window, or null when there is no history — never render
     * "0%" for "not checked yet".
     */
    public function uptimePercent(int $days = 30): ?float
    {
        $row = $this->checks()
            ->where('checked_at', '>=', now()->subDays($days))
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN is_up = 1 THEN 1 ELSE 0 END) as up_count')
            ->first();

        if (! $row || ! $row->total) {
            return null;
        }

        return round(($row->up_count / $row->total) * 100, 2);
    }

    /**
     * Persist one check result: append to the history and refresh the
     * denormalised columns the listing reads.
     */
    public function recordCheck(bool $isUp, ?int $code, ?int $latencyMs, ?string $error = null): ProjectEnvironmentCheck
    {
        return TenantTransaction::run(function () use ($isUp, $code, $latencyMs, $error) {
            $check = $this->checks()->create([
                'checked_at' => now(),
                'is_up' => $isUp,
                'status_code' => $code,
                'latency_ms' => $latencyMs,
                'error' => $error ? mb_substr($error, 0, 255) : null,
            ]);

            $this->forceFill([
                'health_status' => $isUp ? self::HEALTH_UP : self::HEALTH_DOWN,
                'health_code' => $code,
                'health_latency_ms' => $latencyMs,
                'health_error' => $error ? mb_substr($error, 0, 255) : null,
                'health_checked_at' => now(),
                'consecutive_failures' => $isUp ? 0 : $this->consecutive_failures + 1,
                'consecutive_successes' => $isUp ? $this->consecutive_successes + 1 : 0,
            ])->save();

            return $check;
        });
    }
}
