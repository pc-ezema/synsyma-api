<?php

use Illuminate\Support\Facades\Route;
use Modules\Chat\Http\Controllers\ChatController;
use Modules\User\Http\Middleware\EnsureUser;

Route::prefix('v1/chat')->middleware(['auth:sanctum', EnsureUser::class])->group(function () {
    Route::get('conversation', [ChatController::class, 'conversation']);
    Route::get('messages', [ChatController::class, 'messages'])->middleware('throttle:120,1');
    Route::post('messages', [ChatController::class, 'send'])->middleware('throttle:60,1');
    Route::post('read', [ChatController::class, 'markRead']);
    Route::get('messages/{message}/attachment', [ChatController::class, 'attachment'])->name('chat.attachment');
});

Route::prefix('v1/devices')->middleware(['auth:sanctum', EnsureUser::class])->group(function () {
    Route::post('/', [ChatController::class, 'storeDevice'])->middleware('throttle:30,1');
    Route::delete('/', [ChatController::class, 'destroyDevice'])->middleware('throttle:30,1');
});