<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

/*
 * Mounted under both /api and /api/v1 — the D4 decision in docs/desktop-mobile-plan.md. Every unprefixed
 * route keeps answering for the clients already in the field; /v1 is the surface new work targets. The
 * module route files do the same through App\Support\ApiRoutes.
 */
foreach (['', 'v1'] as $version) {
    Route::prefix($version)->group(function () {
        // Throttled per email and address — A1; the limiter is in AppServiceProvider.
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

        // Every group below is gated on its module in the module route files: a company that has not
        // licensed Payroll has no /my-payslips, rather than an empty one.
        Route::middleware('auth:sanctum')->group(function () {
            // The token lifecycle: this device out, a fresh token before expiry, every device, one out.
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/refresh', [AuthController::class, 'refresh']);
            Route::get('/tokens', [AuthController::class, 'tokens']);
            Route::delete('/tokens/{token}', [AuthController::class, 'revoke'])->whereNumber('token');
        });
    });
}
