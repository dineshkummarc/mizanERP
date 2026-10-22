<?php

namespace App\Modules\Quotations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Services\QuotationService;
use Illuminate\Http\Request;

/**
 * The quote as a PDF — `docs/bexio-gap-plan.md` Phase 1, item 1.
 *
 * The quote is looked up here rather than bound by the router, for the reason the invoice
 * controller gives: the company is made current by middleware, and a bound model would have
 * been resolved before that, against the landlord database.
 */
class QuotationPdfController extends Controller
{
    public function __invoke(Request $request, string $company, int $quotation, QuotationService $quotations)
    {
        $record = Quotation::query()->findOrFail($quotation);

        abort_unless($request->user()->can('view', $record), 403);

        return $quotations->renderPdf($record)
            // Opens in the tab it was asked for from; ?download=1 to keep a copy.
            ->inline(! $request->boolean('download'));
    }
}
