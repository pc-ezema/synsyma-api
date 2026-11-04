<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\CatalogController;
use Modules\User\Http\Controllers\UserController;
use Modules\User\Http\Middleware\EnsureUser;


Route::prefix('v1/users')->middleware(['auth:sanctum', EnsureUser::class])->group(function () {    
    Route::get('support/contacts', [UserController::class, 'contacts']);
    Route::delete('account', [UserController::class, 'destroy'])->middleware('throttle:5,1');
    Route::get('categories', [CatalogController::class, 'categories']);
    Route::get('services', [CatalogController::class, 'index']);
    Route::get('services/{id}', [CatalogController::class, 'show']);
});

