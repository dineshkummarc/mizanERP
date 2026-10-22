<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Support\ModuleMap;
use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class JournalEntry extends Model
{
    /**
     * Entries linked to a source record. Reads have to translate the class to its
     * alias exactly as writes do — `where('source_type', Payslip::class)` silently
     * matches nothing once Payslip lives in a module directory, which would leave
     * a superseded payroll entry in place instead of reversing it.
     */
    public function scopeForSource(Builder $query, string $class, int|string|null $id = null): Builder
    {
        $query->where('source_type', ModuleMap::alias($class));

        return $id === null ? $query : $query->where('source_id', $id);
    }

    /**
     * Normalise on write: `source_type` holds a model's stable alias, never its live
     * class name, so the row survives that model moving into a module directory.
     */
    public function setSourceTypeAttribute(?string $value): void
    {
        $this->attributes['source_type'] = $value === null ? null : ModuleMap::alias($value);
    }

    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_POSTED = 'posted';

    protected $fillable = [
        'entry_number', 'entry_date', 'reference', 'memo', 'attachment_path', 'entry_type',
        'status', 'approved_by', 'approved_at', 'rejection_reason',
        'is_posted', 'posted_at', 'created_by', 'fiscal_year_id',
        'source_type', 'source_id', 'transaction_type_id', 'gnucash_id',
    ];

    public function transactionType()
    {
        return $this->belongsTo(TransactionType::class);
    }

    protected $casts = [
        'entry_date' => 'date',
        'is_posted' => 'boolean',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function (JournalEntry $entry) {
            if (empty($entry->entry_number)) {
                $entry->entry_number = static::nextEntryNumber($entry->entry_date);
            }

            if (empty($entry->created_by) && auth()->check()) {
                $entry->created_by = auth()->id();
            }
        });
    }

    public static function nextEntryNumber($entryDate = null): string
    {
        $year = $entryDate
            ? Carbon::parse($entryDate)->year
            : now()->year;

        $lastEntry = static::where('entry_number', 'like', "JE-{$year}-%")
            ->orderByDesc('entry_number')
            ->first();

        $lastNumber = $lastEntry ? (int) substr($lastEntry->entry_number, -6) : 0;

        return sprintf('JE-%s-%06d', $year, $lastNumber + 1);
    }

    public function lines()
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function source()
    {
        return $this->morphTo();
    }

    public function getTotalDebitsAttribute(): float
    {
        return (float) $this->lines()->sum('debit_amount');
    }

    public function getTotalCreditsAttribute(): float
    {
        return (float) $this->lines()->sum('credit_amount');
    }

    public function isBalanced(): bool
    {
        return bccomp(
            number_format($this->total_debits, 2, '.', ''),
            number_format($this->total_credits, 2, '.', ''),
            2
        ) === 0;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function canBePosted(): bool
    {
        return $this->isApproved() && ! $this->is_posted;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED]);
    }
}
