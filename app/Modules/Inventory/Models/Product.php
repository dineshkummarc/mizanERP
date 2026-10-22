<?php

namespace App\Modules\Inventory\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\Account;
use App\Traits\Auditable;

class Product extends Model
{
    use Auditable, HasCustomFields;

    public const METHOD_FIFO = 'fifo';

    public const METHOD_LIFO = 'lifo';

    public const METHOD_AVERAGE = 'average';

    protected $fillable = [
        'sku', 'name', 'description', 'unit', 'list_price', 'valuation_method', 'reorder_level',
        'inventory_account_id', 'cogs_account_id', 'revenue_account_id', 'is_active',
    ];

    protected $casts = [
        // The selling price a line starts from — docs/bexio-gap-plan.md Phase 2, item 8. Null is "type it".
        'list_price' => 'decimal:2',
        'reorder_level' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function movements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function inventoryAccount()
    {
        return $this->belongsTo(Account::class, 'inventory_account_id');
    }

    public function cogsAccount()
    {
        return $this->belongsTo(Account::class, 'cogs_account_id');
    }

    public function revenueAccount()
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }
}
