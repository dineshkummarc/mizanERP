<?php

use App\Modules\Mpr\Http\Controllers\MprController;
use App\Support\ApiRoutes;
use Illuminate\Support\Facades\Route;

/**
 * The module owns its own routes, including the licence gate. A company without
 * MPR has no /my-mprs at all rather than an empty one, so mobile clients must
 * read 403 as "not available for this company".
 */
ApiRoutes::group(['api', 'auth:sanctum', 'api.company', 'module:mpr'], function () {
    Route::get('/my-mprs', [MprController::class, 'index']);
    Route::get('/my-mprs/comparison', [MprController::class, 'comparison']);
    Route::get('/my-mprs/{id}', [MprController::class, 'show']);
});
