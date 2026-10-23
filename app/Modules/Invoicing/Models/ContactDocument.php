<?php

namespace App\Modules\Invoicing\Models;

use App\Models\Concerns\ExpiresOn;
use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A contract or document kept on the contact — docs/bexio-gap-plan.md §3.7.
 *
 * The signed contract, the NDA, a supplier's trade licence or tax registration: the things that lived in
 * somebody's mailbox and were found by asking. EmployeeDocument's shape, because it is the same problem
 * with the same expiry — a contract that ran out last month is noticed when the renewal is disputed.
 */
class ContactDocument extends Model
{
    use Auditable;
    use ExpiresOn;
    use StoresPlainDates;

    protected array $plainDates = ['issued_on', 'expires_on'];

    /** @var array<string, string> */
    public const KINDS = [
        'contract' => 'Contract',
        'nda' => 'NDA',
        'licence' => 'Licence',
        'registration' => 'Registration',
        'tax-certificate' => 'Tax certificate',
        'bank-letter' => 'Bank letter',
        'other' => 'Other',
    ];

    protected $fillable = [
        'contact_id', 'kind', 'title', 'number', 'issued_on', 'expires_on',
        'file_path', 'notes', 'uploaded_by', 'expiry_notified_at_days',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'expires_on' => 'date',
        'expiry_notified_at_days' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $document): void {
            $document->uploaded_by ??= auth()->id();
        });

        // A renewed contract is a new deadline and has to be able to warn again; the once-per-threshold
        // rule would otherwise silence it for ever after one warning. Lifecycle leaves this to the caller
        // (DocumentExpiryCheck::resetOnRenewal); here the model does it, so no screen can forget.
        static::saving(function (self $document): void {
            if ($document->exists && $document->isDirty('expires_on')) {
                $document->expiry_notified_at_days = null;
            }
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst($this->kind);
    }
}
