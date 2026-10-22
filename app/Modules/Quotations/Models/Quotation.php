<?php

namespace App\Modules\Quotations\Models;

use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An offer.
 *
 * **Nothing here reaches the ledger.** A quote is a statement of what something would cost;
 * no money has moved and no obligation exists. docs/crms-plan.md §4 makes this the most
 * important sentence in its section, because a quote that accrued revenue would be an audit
 * finding rather than a bug.
 *
 * **Revising a sent quote creates a new version rather than editing this one.** The customer
 * has version 1 in their inbox; editing it in place makes this system disagree with that
 * inbox, and there is no way to tell which of the two the customer is looking at.
 */
class Quotation extends Model
{
    use Auditable;
    use StoresPlainDates;

    protected array $plainDates = ['issue_date', 'valid_until'];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'number', 'contact_id', 'lead_id', 'opportunity_id', 'currency_code',
        'exchange_rate', 'issue_date', 'valid_until', 'status', 'version',
        'supersedes_id', 'subtotal', 'tax_total', 'total', 'terms', 'notes',
        'accepted_at', 'declined_at', 'decline_reason', 'invoice_id', 'created_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'valid_until' => 'date',
        'version' => 'integer',
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'accepted_at' => 'datetime',
        'declined_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'version' => 1,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $quotation): void {
            $quotation->created_by ??= auth()->id();
            $quotation->issue_date ??= now()->toDateString();
            $quotation->number = $quotation->number ?: static::nextNumber();
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class)->orderBy('sort');
    }

    /** Guarded: null at a company without `invoicing`. */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** Guarded on `crm`: a quote may be raised against a prospect rather than a customer. */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** The version this one replaced. */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** The version that replaced this one, if any. */
    public function supersededBy(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_DRAFT, self::STATUS_SENT]);
    }

    /** Sent, still inside its validity, and not yet answered — what an expiry sweep looks at. */
    public function scopeExpirable(Builder $query, ?string $on = null): Builder
    {
        return $query->where('status', self::STATUS_SENT)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', $on ?: now()->toDateString());
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    /**
     * Whether this quote has run out of time.
     *
     * Computed as well as stored, because the status only changes when the sweep runs and an
     * expired quote must not be acceptable in the meantime — §12.10 asserts exactly that.
     */
    public function hasExpired(string|Carbon|null $on = null): bool
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }

        if (! $this->valid_until) {
            return false;
        }

        return $this->valid_until->lt(Carbon::parse($on ?: now())->startOfDay());
    }

    /** Who it is for, whichever kind of party it is. */
    public function partyLabel(): string
    {
        return $this->contact?->name ?? $this->lead?->display_label ?? 'Unknown';
    }

    /** Where to email it: the customer's correspondence address, or the lead's. Null when there is neither. */
    public function correspondenceEmail(): ?string
    {
        return $this->contact?->correspondenceEmail() ?: $this->lead?->email;
    }

    /**
     * Recompute the totals from the lines.
     *
     * Stored rather than derived on read, unlike most totals here, and for a specific reason:
     * a superseded quote's figures must stay exactly as they were sent even if a tax rate is
     * later corrected. The customer has that number.
     */
    public function recalculate(): self
    {
        $lines = $this->lines()->get();

        $this->forceFill([
            'subtotal' => round($lines->sum(fn (QuotationLine $line): float => $line->netTotal()), 2),
            'tax_total' => round($lines->sum(fn (QuotationLine $line): float => (float) $line->tax_amount), 2),
        ]);

        $this->forceFill([
            'total' => round((float) $this->subtotal + (float) $this->tax_total, 2),
        ])->save();

        return $this;
    }

    /** Sequential per year, in the shape invoice numbers already use. */
    public static function nextNumber(): string
    {
        $prefix = 'QT-'.now()->format('Y').'-';

        $last = static::query()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
