<?php

use App\Modules\Leave\Http\Controllers\LeaveController;
use App\Support\ApiRoutes;
use Illuminate\Support\Facades\Route;

// The same stack as Payroll's /my-payslips: Sanctum caller, company resolved from
// their membership, and the whole surface gated on the module being licensed.
ApiRoutes::group(['api', 'auth:sanctum', 'api.company', 'module:leave'], function () {
    Route::get('/my-leave-balances', [LeaveController::class, 'balances']);
    Route::post('/my-leave-requests', [LeaveController::class, 'apply']);
});
