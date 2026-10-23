<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

// Every group below is gated on its module: a company that has not licensed
// Payroll has no /my-payslips, rather than an empty one. Mobile clients need to
// read 403 as "not available for this company" — these endpoints used to always
// answer, so check the shipped builds before revoking a licence in production.
Route::middleware('auth:sanctum')->group(function () {

    // Employee profile lives in app/Modules/Employees/routes/api.php.

    // MPR lives in app/Modules/Mpr/routes/api.php, loaded by its own provider.

    // Payslips live in app/Modules/Payroll/routes/api.php.

    // Accounting endpoints live in app/Modules/Accounting/routes/api.php.

});
