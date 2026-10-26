<?php

use App\Modules\Employees\Http\Controllers\EmployeeController;
use App\Support\ApiRoutes;
use Illuminate\Support\Facades\Route;

ApiRoutes::group(['api', 'auth:sanctum', 'api.company', 'module:employees'], function () {
    Route::get('/my-profile', [EmployeeController::class, 'myProfile']);
});
