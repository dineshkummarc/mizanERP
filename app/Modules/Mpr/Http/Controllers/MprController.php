<?php

namespace App\Modules\Mpr\Http\Controllers;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Mpr\Models\MPR;
use App\Modules\Mpr\Services\MprPdfService;
use App\Support\Pdf\PdfDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The signed-in user's MPRs, for mobile clients.
 *
 * Runs inside the caller's company (ResolveCompanyFromUser on the route), which is what puts the `public`
 * disk under the company's own directory and makes its URL the access-checked `/files/{id}` route. Reports
 * used to be written with `storage_path('app/public/…')` and linked with `Storage::url()` — the *default*
 * disk's URL — so every company's PDFs landed in one shared directory and `pdf_url` was `/storage/Mpr/…`:
 * a path nothing serves unless that directory is linked into the web root, at which point it serves to
 * anybody who has the guessable name.
 */
class MprController extends Controller
{
    /**
     * 1. Get All MPRs (Clean List with User Name)
     */
    public function index(Request $request)
    {
        $mprs = MPR::where('user_id', $request->user()->id)
            ->orderBy('id', 'desc')
            ->get()
            ->map(fn (MPR $mpr): array => [
                'id' => $mpr->id,
                'title' => $mpr->title,
                'remarks' => $mpr->remarks,
                'pdf_url' => $this->singleReportUrl($mpr, $request->user()->name),
            ]);

        return ApiResponse::success(['count' => $mprs->count(),
            'data' => $mprs]);
    }

    /**
     * 2. Get Comparison Report (Temporary Files)
     */
    public function comparison(Request $request)
    {
        $result = (new MprPdfService)->generateComparisonReport($request->user()->id);

        if (! $result || $result['empty']) {
            // 404 on the versioned surface, where "no record" is correctly not-found; the unprefixed route
            // keeps its original 400 for clients already built against it (D4).
            return ApiResponse::error(
                'This user has no MPR Record for comparison',
                $request->is('api/v1/*') ? 404 : 400,
            );
        }

        $fileName = 'Mpr/'.$this->cleanName($request->user()->name).'_Comparison_'.Str::random(40).'.pdf';

        return ApiResponse::success(['message' => 'Comparison report generated successfully',
            'pdf_url' => $this->store($fileName, $result['pdf'])]);
    }

    /**
     * 3. Get a Single MPR (Clean Content with User Name)
     */
    public function show(Request $request, $id)
    {
        $mpr = MPR::where('id', $id)->where('user_id', $request->user()->id)->first();

        if (! $mpr) {
            return ApiResponse::error('MPR not found or unauthorized', 404);
        }

        return ApiResponse::success(['data' => [
            'id' => $mpr->id,
            'title' => $mpr->title,
            'remarks' => $mpr->remarks,
            'pdf_url' => $this->singleReportUrl($mpr, $request->user()->name),
        ]]);
    }

    /** The stored single report: rendered on first request, remembered in `pdf_path`, reused after. */
    private function singleReportUrl(MPR $mpr, string $userName): string
    {
        if (! $mpr->pdf_path || ! Storage::disk('public')->exists($mpr->pdf_path)) {
            $fileName = 'Mpr/'.$this->cleanName($userName).'_'.Str::random(40).'.pdf';

            $this->store($fileName, (new MprPdfService)->generateSingleReport($mpr->toArray())['pdf']);
            $mpr->update(['pdf_path' => $fileName]);
        }

        return $this->urlFor($mpr->pdf_path);
    }

    /** Through the disk, never a literal path, so the file lands under the current company's root. */
    private function store(string $fileName, PdfDocument $pdf): string
    {
        Storage::disk('public')->put($fileName, $pdf->raw());

        return $this->urlFor($fileName);
    }

    /** Absolute, because a mobile client resolves nothing relative. */
    private function urlFor(string $fileName): string
    {
        return url(Storage::disk('public')->url($fileName));
    }

    private function cleanName(string $userName): string
    {
        return str_replace([' ', '/', '\\'], '_', $userName);
    }
}
