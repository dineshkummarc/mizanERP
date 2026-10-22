<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\Bank;
use App\Traits\Auditable;

class CompanyBankAccount extends Model
{
    use Auditable;

    protected $fillable = [
        'title', 'bank_id', 'account_no', 'iban', 'statement_mapping',
        'transaction_type_id', 'is_default', 'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        // How this bank's statement CSV is laid out — see StatementCsv::FIELDS.
        'statement_mapping' => 'array',
    ];

    protected static function booted()
    {
        // Only one default account per transaction type.
        static::saved(function (CompanyBankAccount $account) {
            if ($account->is_default && $account->transaction_type_id) {
                static::where('transaction_type_id', $account->transaction_type_id)
                    ->whereKeyNot($account->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    public function transactionType()
    {
        return $this->belongsTo(TransactionType::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
