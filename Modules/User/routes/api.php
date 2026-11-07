<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\BookingController;
use Modules\User\Http\Controllers\AddressController;
use Modules\User\Http\Controllers\BookingChangeController;
use Modules\User\Http\Controllers\CatalogController;
use Modules\User\Http\Controllers\PaymentController;
use Modules\User\Http\Controllers\PayPalController;
use Modules\User\Http\Controllers\StripeWebhookController;
use Modules\User\Http\Controllers\UserController;
use Modules\User\Http\Middleware\EnsureUser;


Route::prefix('v1/users')->middleware(['auth:sanctum', EnsureUser::class])->group(function () {    
    Route::get('support/contacts', [UserController::class, 'contacts']);
    Route::delete('account', [UserController::class, 'destroy'])->middleware('throttle:5,1');
    Route::get('categories', [CatalogController::class, 'categories']);
    Route::get('services', [CatalogController::class, 'index']);
    Route::get('services/{id}', [CatalogController::class, 'show']);

    Route::get('addresses', [AddressController::class, 'index']);
    Route::post('addresses', [AddressController::class, 'store']);
    Route::patch('addresses/{id}', [AddressController::class, 'update']);
    Route::delete('addresses/{id}', [AddressController::class, 'destroy']);

    Route::get('bookings', [BookingController::class, 'index']);          // ?scope=active|past
    Route::post('bookings/preview', [BookingController::class, 'preview']);
    Route::post('bookings', [BookingController::class, 'store']);
    Route::get('bookings/{id}', [BookingController::class, 'show']);

    Route::get('payment-methods', [PaymentController::class, 'methods']);

    Route::post('bookings/{id}/pay', [PaymentController::class, 'start']);
    Route::post('bookings/{id}/pay/sync', [PaymentController::class, 'sync']);

    Route::get('bookings/{id}/cancel-preview', [BookingChangeController::class, 'cancelPreview']);
    Route::post('bookings/{id}/cancel', [BookingChangeController::class, 'cancel']);

    Route::get('bookings/{id}/options', [BookingChangeController::class, 'options']);
    Route::post('bookings/{id}/reschedule', [BookingChangeController::class, 'reschedule']);
});
Route::get('v1/public/support-contacts', [UserController::class, 'contacts'])->middleware('throttle:60,1');
Route::post('v1/webhooks/paypal', [PayPalController::class, 'webhook']);
Route::get('v1/paypal/return', [PayPalController::class, 'approved'])->name('paypal.return');
Route::get('v1/paypal/cancel', [PayPalController::class, 'cancelled'])->name('paypal.cancel');