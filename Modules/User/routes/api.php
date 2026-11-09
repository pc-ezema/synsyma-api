<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\BookingController;
use Modules\User\Http\Controllers\AddressController;
use Modules\User\Http\Controllers\BannerController;
use Modules\User\Http\Controllers\BookingChangeController;
use Modules\User\Http\Controllers\CardController;
use Modules\User\Http\Controllers\CatalogController;
use Modules\User\Http\Controllers\HomeController;
use Modules\User\Http\Controllers\NotificationController;
use Modules\User\Http\Controllers\PaymentController;
use Modules\User\Http\Controllers\PayPalController;
use Modules\User\Http\Controllers\QuoteController;
use Modules\User\Http\Controllers\ReviewController;
use Modules\User\Http\Controllers\StripeWebhookController;
use Modules\User\Http\Controllers\UserController;
use Modules\User\Http\Middleware\EnsureUser;


Route::prefix('v1/users')->middleware(['auth:sanctum', EnsureUser::class])->group(function () {    
    Route::get('home', [HomeController::class, 'summary']);

    Route::get('support/contacts', [UserController::class, 'contacts']);
    Route::delete('account', [UserController::class, 'destroy'])->middleware('throttle:5,1');
    Route::get('categories', [CatalogController::class, 'categories']);
    Route::get('services', [CatalogController::class, 'index']);
    Route::get('services/popular', [CatalogController::class, 'popular']); 
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

    Route::get('banners', [BannerController::class, 'index']);

    Route::get('cards', [CardController::class, 'index']);
    Route::delete('cards/{id}', [CardController::class, 'destroy']);

    Route::post('bookings/{id}/quote/accept', [QuoteController::class, 'accept']);
    Route::post('bookings/{id}/quote/decline', [QuoteController::class, 'decline']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unread']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'read']);

    Route::put('bookings/{id}/review', [ReviewController::class, 'save']);
});
Route::get('v1/public/support-contacts', [UserController::class, 'contacts'])->middleware('throttle:60,1');
Route::post('v1/webhooks/paypal', [PayPalController::class, 'webhook']);
Route::get('v1/paypal/return', [PayPalController::class, 'approved'])->name('paypal.return');
Route::get('v1/paypal/cancel', [PayPalController::class, 'cancelled'])->name('paypal.cancel');