<?php

namespace App\Modules\Payroll\Models;

use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\Account;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * One part of pay: an allowance, or a deduction.
 *
 * The shipped ones are marked `is_column_backed` — basic wage, the four allowances,
 * bonus, extra work, and the four deductions all still live in their own columns on
 * EmployeeSetting and Payslip, and the calculation still reads those. They are rows
 * here so the set of things pay is made of is complete, reportable, and knows where
 * it posts.
 *
 * Anything added afterwards is not column-backed, and *is* driven from here: an
 * amount per employee, a total on the payslip, and a place in the ledger. That is
 * the point — a new allowance is a row, not a migration and twelve edits.
 */
class PayComponent extends Model
{
    use Auditable;

    public const KIND_EARNING = 'earning';

    public const KIND_DEDUCTION = 'deduction';

    /** What the amount is per — docs/bexio-gap-plan.md Phase 5, item 20. See ComponentAmount. */
    public const BASIS_MONTHLY = 'monthly';

    public const BASIS_PER_HOUR = 'per_hour';

    public const BASIS_ANNUAL = 'annual';

    /** @var array<int, string> the payroll year's months, July first — what `pays_in_month` is chosen from */
    public const MONTHS = ['July', 'August', 'September', 'October', 'November', 'December', 'January', 'February', 'March', 'April', 'May', 'June'];

    protected $fillable = [
        'code', 'label', 'kind', 'basis', 'pays_in_month', 'account_key', 'account_id',
        'is_taxable', 'is_column_backed', 'is_active', 'sort', 'description',
        'prorates',
    ];

    protected $casts = [
        'is_taxable' => 'boolean',
        'is_column_backed' => 'boolean',
        'prorates' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    protected $attributes = [
        'kind' => self::KIND_EARNING,
        'basis' => self::BASIS_MONTHLY,
        'is_taxable' => true,
        'is_column_backed' => false,
        // False, which is the conservative direction: an existing allowance keeps
        // paying in full until somebody says it should scale with attendance.
        // Defaulting true would quietly reduce every allowance in the first month a
        // company switched pro-rating on.
        'prorates' => false,
        'is_active' => true,
        'sort' => 100,
    ];

    protected static function booted(): void
    {
        /*
         * A data-driven component must say where it posts.
         *
         * Without an account the payslip still pays it, so net salary includes money
         * with no debit behind it and the journal entry will not balance — a real
         * failure, but one that surfaces as "debits 420,000 != credits 425,000" while
         * somebody is saving a payslip, pointing at nothing. Refused here instead,
         * where the fix is.
         */
        static::deleting(function (self $component): void {
            if ($component->is_column_backed) {
                throw new InvalidArgumentException(
                    "\"{$component->label}\" is part of payroll's own arithmetic and cannot be removed. "
                    .'Switch it off if it should stop being paid.'
                );
            }

            if ($component->payslipAmounts()->exists()) {
                throw new InvalidArgumentException(
                    "\"{$component->label}\" has been paid on ".$component->payslipAmounts()->count()
                    .' payslip(s), so it is part of what those payslips say. Switch it off instead.'
                );
            }
        });

        static::saving(function (self $component): void {
            if ($component->is_column_backed || $component->account_id || $component->account_key) {
                return;
            }

            throw new InvalidArgumentException(
                "Pay component \"{$component->label}\" needs an account, or a payroll account key, "
                .'or a payslip that includes it cannot be posted.'
            );
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function settingAmounts(): HasMany
    {
        return $this->hasMany(EmployeeSettingComponent::class);
    }

    public function payslipAmounts(): HasMany
    {
        return $this->hasMany(PayslipComponent::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeEarnings(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_EARNING);
    }

    public function scopeDeductions(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_DEDUCTION);
    }

    /**
     * The ones the calculation reads from components rather than from a column.
     *
     * Everything the system shipped with is still column-backed; this scope is what
     * makes an added allowance work without touching payroll's arithmetic.
     */
    public function scopeDataDriven(Builder $query): Builder
    {
        return $query->where('is_column_backed', false);
    }

    public function isEarning(): bool
    {
        return $this->kind === self::KIND_EARNING;
    }

    /**
     * Where this component posts.
     *
     * Its own account if it names one, otherwise the payroll mapping it names a key
     * for — the same mapping the column-backed components already post through, so a
     * new allowance does not need a second way of finding an account.
     */
    public function accountId(): ?int
    {
        if ($this->account_id) {
            return $this->account_id;
        }

        if (! $this->account_key) {
            return null;
        }

        return \App\Modules\Accounting\Support\PayrollAccounts::id($this->account_key);
    }
}
