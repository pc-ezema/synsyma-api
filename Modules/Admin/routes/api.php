<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Http\Controllers\AuthController;
use Modules\Admin\Http\Middleware\EnsureAdmin;

Route::prefix('v1/admin')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:6,1');

    Route::middleware(['auth:sanctum', EnsureAdmin::class])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
    });
});