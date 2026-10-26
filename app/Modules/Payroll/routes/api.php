<?php

use App\Modules\Payroll\Http\Controllers\PayslipController;
use App\Support\ApiRoutes;
use Illuminate\Support\Facades\Route;

ApiRoutes::group(['api', 'auth:sanctum', 'api.company', 'module:payroll'], function () {
    Route::get('/my-payslips', [PayslipController::class, 'index']);

    // Rendered on request, never served from disk, so the PDF always matches
    // the payslip as it stands. Named, because index() builds the URL.
    Route::get('/my-payslips/{payslip}/pdf', [PayslipController::class, 'pdf'])
        ->whereNumber('payslip')
        ->name('payslips.pdf');

    // The payslip's comment thread, for the employee portal — Phase 7 of
    // docs/accounting-implementation-plan.md. Same policy checks as the
    // Filament comments tab: an employee sees only their own payslip's thread.
    Route::get('/payslips/{payslip}/comments', [PayslipController::class, 'comments'])
        ->whereNumber('payslip');
    Route::post('/payslips/{payslip}/comments', [PayslipController::class, 'addComment'])
        ->whereNumber('payslip');
});
