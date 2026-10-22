<?php

namespace App\Modules\Invoicing\Filament\Support;

use App\Modules\Inventory\Models\Product;
use App\Modules\Invoicing\Models\Invoice;

/**
 * What choosing a product does to an invoice line — `docs/bexio-gap-plan.md` Phase 2, items 5 and 8.
 *
 * Shared by the invoice's Lines tab and the standalone line form so the two cannot disagree. Every
 * value it sets is a starting point the person can still change; a product with no list price fills
 * only the description, and a bill fills no price at all, because a list price is a selling price.
 */
class LinePricing
{
    public static function applyProduct(?Invoice $invoice, mixed $productId, callable $get, callable $set): void
    {
        $product = $productId ? Product::query()->find($productId) : null;

        if ($product === null) {
            return;
        }

        if (blank($get('description'))) {
            $set('description', $product->name);
        }

        $sells = $invoice !== null && in_array($invoice->kind, [Invoice::KIND_SALE, Invoice::KIND_CREDIT_NOTE], true);

        if ($sells && $product->list_price !== null) {
            $price = (float) $product->list_price;

            // The customer's standing discount comes off here, and the line carries the result.
            $set('unit_price', $invoice->contact?->discounted($price) ?? $price);
        }

        self::fillTotal($get, $set);
    }

    /** Quantity × unit price, which is what `InvoiceService::validateTotals()` will insist on at issue. */
    public static function fillTotal(callable $get, callable $set): void
    {
        $set('line_total', round((float) $get('quantity') * (float) $get('unit_price'), 2));
    }
}
