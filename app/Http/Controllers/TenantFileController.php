<?php

namespace App\Http\Controllers;

use App\Modules\Core\Models\Company;
use App\Support\TenantFileAccess;
use App\Support\TenantStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a company's stored files (payslip/MPR PDFs, NIC scans, petty-cash
 * receipts) through the application.
 *
 * This replaces linking `storage/app/public` into the document root: that
 * symlink cannot be created on every host, and it would also make one company's
 * files reachable by anyone who knows a path. Here membership is checked first.
 *
 * Reachable with either a panel session or a Sanctum token, so the `pdf_url`
 * the API hands back works for mobile clients too.
 */
class TenantFileController extends Controller
{
    public function show(Request $request, Company $company, string $path): StreamedResponse
    {
        abort_unless(
            $request->user()?->canAccessTenant($company) ?? false,
            403,
            'You do not have access to this company.'
        );

        abort_unless(TenantStorage::isSafePath($path), 404);

        // Membership is the floor. A module that owns the document behind this path may say more —
        // a restricted drawing streams only to somebody its policy lets view it. See TenantFileAccess.
        abort_unless(TenantFileAccess::allows($request->user(), $company, $path), 403, 'You do not have access to this file.');

        // A disk built for this company only — the request cannot address
        // anything outside its own directory.
        $disk = TenantStorage::publicDisk($company);

        abort_unless($disk->exists($path), 404);

        // Inline so PDFs and images open in the browser; ?download=1 forces the
        // save dialog.
        $response = $request->boolean('download')
            ? $disk->download($path, basename($path))
            : $disk->response($path, basename($path));

        // Defence in depth for an image that is really a script (an SVG slipped past an upload filter):
        // no sniffing, and a policy that lets the file render but run nothing — security audit finding 5.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");

        return $response;
    }
}
