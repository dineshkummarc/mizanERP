<?php

namespace App\Modules\Invoicing\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\TenantModel as Model;
use App\Modules\Core\Models\Bank;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    use Auditable, HasCustomFields;

    public const KIND_CUSTOMER = 'customer';

    public const KIND_SUPPLIER = 'supplier';

    public const KIND_BOTH = 'both';

    protected $fillable = [
        'name', 'kind', 'email', 'phone', 'address_line_1', 'address_line_2',
        'ntn', 'cnic', 'bank_id', 'is_active', 'payment_terms_days', 'credit_limit', 'default_tax_rate_id',
        // The account columns — docs/bexio-gap-plan.md Phase 2, item 5. `owner_employee_id` has no
        // relation here on purpose: Invoicing may not name Employees, so EmployeeOptions labels it.
        'category', 'owner_employee_id', 'default_discount_pct',
        // Where a supplier is paid — Phase 3. Read by the bank file exactly as a beneficiary's are.
        'account_no', 'iban',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'payment_terms_days' => 'integer',
        // Null is no limit — see the migration. `InvoiceService::issue()` is the only reader.
        'credit_limit' => 'decimal:2',
        'default_discount_pct' => 'decimal:2',
    ];

    /**
     * The terms worth offering as a choice. Anything else can be typed.
     *
     * Null is deliberately absent from the list and offered as its own option on
     * the form: "none agreed" and "due on receipt" are different facts, and a
     * dropdown that silently treats one as the other would put every contact
     * nobody has thought about into the overdue bucket on day one.
     *
     * @var array<int, string>
     */
    public const TERMS = [
        0 => 'Due on receipt',
        7 => 'Net 7 days',
        15 => 'Net 15 days',
        30 => 'Net 30 days',
        45 => 'Net 45 days',
        60 => 'Net 60 days',
        90 => 'Net 90 days',
    ];

    public function paymentTermsLabel(): string
    {
        if ($this->payment_terms_days === null) {
            return 'None agreed';
        }

        return self::TERMS[$this->payment_terms_days] ?? "Net {$this->payment_terms_days} days";
    }

    /**
     * When an invoice dated $invoiceDate falls due under this contact's terms,
     * or null when none are agreed.
     */
    public function dueDateFor(\Illuminate\Support\Carbon|string $invoiceDate): ?\Illuminate\Support\Carbon
    {
        if ($this->payment_terms_days === null) {
            return null;
        }

        return \Illuminate\Support\Carbon::parse($invoiceDate)
            ->startOfDay()
            ->addDays($this->payment_terms_days);
    }

    public function people()
    {
        return $this->hasMany(ContactPerson::class);
    }

    /**
     * Where correspondence goes.
     *
     * The primary named person if there is one, otherwise the contact's own address —
     * so adding people to a client changes who is written to, and adding none changes
     * nothing.
     */
    public function correspondenceEmail(): ?string
    {
        $primary = $this->people()->where('is_primary', true)->value('email');

        return $primary ?: $this->email;
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * The rate this party's invoice lines start on — null meaning the company default.
     *
     * Read by the line forms only. Nothing recalculates an issued invoice from it: `applyTaxes()` works
     * from the rate on each line, which is the rate that was actually charged.
     */
    public function defaultTaxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'default_tax_rate_id');
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    public function isCustomer(): bool
    {
        return in_array($this->kind, [self::KIND_CUSTOMER, self::KIND_BOTH]);
    }

    public function isSupplier(): bool
    {
        return in_array($this->kind, [self::KIND_SUPPLIER, self::KIND_BOTH]);
    }

    /**
     * A catalogue price with this party's standing discount taken off — Phase 2, item 5.
     *
     * The line carries the result, not the negotiation: the same rule a converted quote follows, which
     * is why there is no discount column on invoice lines and nothing downstream of a line changed.
     */
    public function discounted(float $price): float
    {
        return round($price * (1 - (float) $this->default_discount_pct / 100), 2);
    }

    /**
     * What they owe right now: open sales less open credit notes, signed.
     *
     * Negative means the customer is in credit. The Statement action answers the same question for a
     * period with the movements; this is the one figure for the top of the screen.
     */
    public function openBalance(): float
    {
        return round($this->invoices()
            ->whereIn('kind', [Invoice::KIND_SALE, Invoice::KIND_CREDIT_NOTE])
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])
            ->get()
            ->sum(fn (Invoice $invoice): float => $invoice->signedOutstanding()), 2);
    }

    /**
     * Every category in use, for the datalist and the filter.
     *
     * Free text offered back rather than a settings list: the second contact in a category gets it
     * from the first, and nobody administers anything.
     *
     * @return array<int, string>
     */
    public static function categories(): array
    {
        return static::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();
    }
}
