<?php

use App\Modules\Accounting\Http\Controllers\AccountController;
use App\Modules\Accounting\Http\Controllers\ReportController;
use App\Support\ApiRoutes;
use Illuminate\Support\Facades\Route;

ApiRoutes::group(['api', 'auth:sanctum', 'api.company', 'module:accounting'], function () {
    Route::get('/reports/trial-balance', [ReportController::class, 'trialBalance']);
    Route::get('/reports/profit-and-loss', [ReportController::class, 'profitAndLoss']);

    Route::get('/accounts/tree', [AccountController::class, 'tree']);
    Route::apiResource('accounts', AccountController::class);
});
