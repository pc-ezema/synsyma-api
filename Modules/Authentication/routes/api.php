<?php

use Illuminate\Support\Facades\Route;
use Modules\Authentication\Http\Controllers\AuthenticationController;
use Modules\User\Http\Middleware\EnsureUser;

Route::prefix('v1/auth')->group(function () {
    Route::post('register', [AuthenticationController::class, 'register'])->middleware('throttle:10,1');
    Route::post('login', [AuthenticationController::class, 'login'])->middleware('throttle:6,1');
    Route::post('verify-email', [AuthenticationController::class, 'verifyEmail'])->middleware('throttle:10,1');
    Route::post('resend-otp', [AuthenticationController::class, 'resendOtp'])->middleware('throttle:10,1');
    Route::post('forgot-password', [AuthenticationController::class, 'forgotPassword'])->middleware('throttle:10,1');
    Route::post('verify-reset-code', [AuthenticationController::class, 'verifyResetCode'])->middleware('throttle:10,1');
    Route::post('reset-password', [AuthenticationController::class, 'resetPassword'])->middleware('throttle:10,1');
    Route::post('refresh', [AuthenticationController::class, 'refresh'])->middleware('throttle:20,1');

    Route::middleware(['auth:sanctum', EnsureUser::class])->group(function () {
        Route::post('logout', [AuthenticationController::class, 'logout']);
        Route::get('me', [AuthenticationController::class, 'me']);
    });
});