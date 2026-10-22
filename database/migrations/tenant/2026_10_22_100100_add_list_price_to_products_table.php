<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A product's selling price — `docs/bexio-gap-plan.md` Phase 2, item 8.
     *
     * `products` had a SKU, a unit, a valuation method and three ledger accounts, and no price: choosing
     * one on an invoice line filled nothing, so every line was typed. Nullable, because a product that is
     * only ever bought has no selling price, and a null is "type it" rather than "free".
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('list_price', 12, 2)->nullable()->after('unit');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('list_price');
        });
    }
};
