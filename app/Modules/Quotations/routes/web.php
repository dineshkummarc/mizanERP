<?php

use App\Modules\Quotations\Http\Controllers\QuotationPdfController;
use Illuminate\Support\Facades\Route;

/**
 * The quote PDF. Shaped exactly like `invoice.pdf`: the licence gate is on the route because a
 * direct URL never consults canAccess(), the company is in the path because a quote lives in
 * the tenant database, and the quote is a plain id because binding would run before the
 * company is current. See ResolveCompanyFromRoute.
 */
Route::middleware(['web', 'auth', 'company', 'module:quotations'])
    ->get('/reports/{company}/quotation/{quotation}/pdf', QuotationPdfController::class)
    ->whereNumber('quotation')
    ->name('quotation.pdf');
