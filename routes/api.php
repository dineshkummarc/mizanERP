<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

// Throttled per email and address — docs/desktop-mobile-plan.md A1; the limiter is in AppServiceProvider.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Every group below is gated on its module: a company that has not licensed
// Payroll has no /my-payslips, rather than an empty one. Mobile clients need to
// read 403 as "not available for this company" — these endpoints used to always
// answer, so check the shipped builds before revoking a licence in production.
Route::middleware('auth:sanctum')->group(function () {

    // The token lifecycle: this device out, a fresh token before expiry, every device, one device out.
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::get('/tokens', [AuthController::class, 'tokens']);
    Route::delete('/tokens/{token}', [AuthController::class, 'revoke'])->whereNumber('token');

    // Employee profile lives in app/Modules/Employees/routes/api.php.

    // MPR lives in app/Modules/Mpr/routes/api.php, loaded by its own provider.

    // Payslips live in app/Modules/Payroll/routes/api.php.

    // Accounting endpoints live in app/Modules/Accounting/routes/api.php.

});
