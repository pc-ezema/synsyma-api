<?php

use Illuminate\Support\Facades\Route;
use Modules\Profile\Http\Controllers\ProfileController;
use Modules\User\Http\Middleware\EnsureUser;

Route::prefix('v1/profile')->middleware(['auth:sanctum', EnsureUser::class])->group(function () {
    Route::get('/', [ProfileController::class, 'show']);
    Route::patch('/', [ProfileController::class, 'update']);

    Route::post('photo', [ProfileController::class, 'uploadPhoto'])->middleware('throttle:10,1');
    Route::delete('photo', [ProfileController::class, 'deletePhoto']);

    Route::post('password/request-code', [ProfileController::class, 'requestCode'])->middleware('throttle:5,1');
    Route::post('password/verify-code', [ProfileController::class, 'verifyCode'])->middleware('throttle:10,1');
    Route::post('password', [ProfileController::class, 'updatePassword'])->middleware('throttle:10,1');
});