<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Http\Controllers\AccessControlController;
use Modules\Admin\Http\Controllers\AccountDeletionController;
use Modules\Admin\Http\Controllers\AdminBookingController;
use Modules\Admin\Http\Controllers\AdminBookingPolicyController;
use Modules\Admin\Http\Controllers\AdminChatController;
use Modules\Admin\Http\Controllers\AdminController;
use Modules\Admin\Http\Controllers\AdminPaymentController;
use Modules\Admin\Http\Controllers\AdminPromoBannerController;
use Modules\Admin\Http\Controllers\AdminPromoCodeController;
use Modules\Admin\Http\Controllers\AuthController;
use Modules\Admin\Http\Controllers\ContractorCampaignController;
use Modules\Admin\Http\Controllers\ContractorController;
use Modules\Admin\Http\Controllers\ContractorDocumentController;
use Modules\Admin\Http\Controllers\PublicJoinController;
use Modules\Admin\Http\Controllers\ServiceCategoryController;
use Modules\Admin\Http\Controllers\ServiceController;
use Modules\Admin\Http\Controllers\SupportContactController;
use Modules\Admin\Http\Middleware\EnsureAdmin;

Route::prefix('v1/admin')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::post('auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:30,1');

    Route::middleware(['auth:sanctum', EnsureAdmin::class])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::prefix('chat')->group(function () {
            Route::get('unread-count', [AdminChatController::class, 'unreadCount'])->middleware('permission:chat.view');
            Route::get('conversations', [AdminChatController::class, 'conversations'])->middleware('permission:chat.view');
            Route::get('conversations/{conversation}', [AdminChatController::class, 'show'])->middleware('permission:chat.view');
            Route::get('conversations/{conversation}/messages', [AdminChatController::class, 'messages'])
                ->middleware(['permission:chat.view', 'throttle:120,1']);
            Route::post('conversations/{conversation}/read', [AdminChatController::class, 'markRead'])->middleware('permission:chat.view');
            Route::post('conversations/{conversation}/messages', [AdminChatController::class, 'send'])
                ->middleware(['permission:chat.reply', 'throttle:60,1']);
            Route::patch('conversations/{conversation}', [AdminChatController::class, 'update'])->middleware('permission:chat.moderate');
            Route::delete('messages/{message}', [AdminChatController::class, 'destroyMessage'])->middleware('permission:chat.moderate');
            Route::get('messages/{message}/attachment', [AdminChatController::class, 'attachment'])
                ->middleware('permission:chat.view')
                ->name('admin.chat.attachment');
        });

        Route::prefix('devices')->group(function () {
            Route::post('/', [AdminChatController::class, 'storeDevice'])->middleware('throttle:30,1');
            Route::delete('/', [AdminChatController::class, 'destroyDevice'])->middleware('throttle:30,1');
        });

        Route::get('admins', [AdminController::class, 'admins'])->middleware('permission:admins.view');
        Route::post('admins', [AdminController::class, 'storeAdmin'])->middleware('permission:admins.create');
        Route::patch('admins/{admin}', [AdminController::class, 'updateAdmin'])->middleware('permission:admins.update');
        Route::delete('admins/{admin}', [AdminController::class, 'destroyAdmin'])->middleware('permission:admins.delete');
        Route::post('admins/{admin}/resend-credentials', [AdminController::class, 'resendCredentials'])->middleware(['permission:admins.resend', 'throttle:10,1']);

        Route::prefix('access-control')->group(function () {
            Route::get('permissions', [AccessControlController::class, 'permissions'])
                ->name('admin.permissions.index')
                ->middleware('permission:permission.view');
            Route::get('roles', [AccessControlController::class, 'roles'])
                ->name('admin.roles.index')
                ->middleware('permission:roles.index');
            Route::get('roles/{role}', [AccessControlController::class, 'showRole'])
                ->name('admin.roles.show')
                ->middleware('permission:roles.show');
            Route::get('roles/{role}/admins', [AccessControlController::class, 'roleAdmins'])
                ->name('admin.roles.admins')
                ->middleware('permission:roles.admins');
            Route::post('permissions/sync', [AccessControlController::class, 'syncPermissions'])
                ->name('admin.permissions.sync')
                ->middleware('permission:permission.sync');
            Route::post('roles', [AccessControlController::class, 'storeRole'])
                ->name('admin.roles.store')
                ->middleware('permission:roles.store');
            Route::patch('roles/{role}', [AccessControlController::class, 'updateRole'])
                ->name('admin.roles.update')
                ->middleware('permission:roles.update');
            Route::delete('roles/{role}', [AccessControlController::class, 'destroyRole'])
                ->name('admin.roles.destroy')
                ->middleware('permission:roles.destroy');
            Route::put('roles/{role}/permissions', [AccessControlController::class, 'syncRolePermissions'])
                ->name('admin.roles.permissions.sync')
                ->middleware('permission:roles.permissions.sync');

            Route::put('admins/{admin}/roles', [AccessControlController::class, 'syncAdminRoles'])
                ->name('admin.admins.roles.sync')
                ->middleware('permission:admins.roles.sync');
            Route::post('admins/{admin}/roles/{role}', [AccessControlController::class, 'assignRole'])
                ->name('admin.admins.roles.assign')
                ->middleware('permission:admins.roles.assign');
            Route::delete('admins/{admin}/roles/{role}', [AccessControlController::class, 'removeRole'])
                ->name('admin.admins.roles.remove')
                ->middleware('permission:admins.roles.remove');
            Route::post('admins/{admin}/permissions', [AccessControlController::class, 'givePermission'])
                ->name('admin.admins.permissions.give')
                ->middleware('permission:admins.permissions.give');
            Route::delete('admins/{admin}/permissions', [AccessControlController::class, 'revokePermission'])
                ->name('admin.admins.permissions.revoke')
                ->middleware('permission:admins.permissions.revoke');
        });

        Route::prefix('profile')->group(function () {
            Route::get('/', [AdminController::class, 'show']);
            Route::patch('/', [AdminController::class, 'update'])->middleware('throttle:20,1');
            Route::post('photo', [AdminController::class, 'photo'])->middleware('throttle:10,1');
            Route::delete('photo', [AdminController::class, 'destroyPhoto'])->middleware('throttle:10,1');
            Route::put('password', [AdminController::class, 'password'])->middleware('throttle:6,1');
        });

        Route::prefix('support/contacts')->group(function () {
            Route::get('/', [SupportContactController::class, 'index'])->middleware('permission:support.view');
            Route::post('/', [SupportContactController::class, 'store'])->middleware('permission:support.create');
            Route::patch('{id}', [SupportContactController::class, 'update'])->middleware('permission:support.update');
            Route::delete('{id}', [SupportContactController::class, 'destroy'])->middleware('permission:support.delete');
        });

        Route::prefix('account-deletions')->group(function () {
            Route::get('/', [AccountDeletionController::class, 'index'])->middleware('permission:deletions.view');
            Route::get('summary', [AccountDeletionController::class, 'summary'])->middleware('permission:deletions.view');
        });

        Route::prefix('service-categories')->group(function () {
            Route::get('/', [ServiceCategoryController::class, 'index'])->middleware('permission:categories.view');
            Route::post('/', [ServiceCategoryController::class, 'store'])->middleware('permission:categories.create');
            Route::patch('{id}', [ServiceCategoryController::class, 'update'])->middleware('permission:categories.update');
            Route::delete('{id}', [ServiceCategoryController::class, 'destroy'])->middleware('permission:categories.delete');
        });

        Route::prefix('services')->group(function () {
            Route::get('/', [ServiceController::class, 'index'])->middleware('permission:services.view');
            Route::get('{id}', [ServiceController::class, 'show'])->middleware('permission:services.view');
            Route::post('/', [ServiceController::class, 'store'])->middleware('permission:services.create');
            Route::patch('{id}', [ServiceController::class, 'update'])->middleware('permission:services.update');
            Route::delete('{id}', [ServiceController::class, 'destroy'])->middleware('permission:services.delete');
        });

        Route::prefix('contractors')->group(function () {
            Route::get('/', [ContractorController::class, 'index'])->middleware('permission:contractors.view');
            Route::get('summary', [ContractorController::class, 'summary'])->middleware('permission:contractors.view');
            Route::post('/', [ContractorController::class, 'store'])->middleware('permission:contractors.create');
            Route::get('{id}', [ContractorController::class, 'show'])->middleware('permission:contractors.view');
            Route::patch('{id}', [ContractorController::class, 'update'])->middleware('permission:contractors.update');
            Route::patch('{id}/status', [ContractorController::class, 'updateStatus'])->middleware('permission:contractors.update');
            Route::delete('{id}', [ContractorController::class, 'destroy'])->middleware('permission:contractors.delete');

            Route::post('{contractorId}/documents', [ContractorDocumentController::class, 'store'])->middleware('permission:contractors.update');
            Route::get('{contractorId}/documents/{id}', [ContractorDocumentController::class, 'download'])->middleware('permission:contractors.view');
            Route::patch('{contractorId}/documents/{id}', [ContractorDocumentController::class, 'update'])->middleware('permission:contractors.update');
            Route::delete('{contractorId}/documents/{id}', [ContractorDocumentController::class, 'destroy'])->middleware('permission:contractors.update');
        });

        Route::prefix('contractor-campaigns')->group(function () {
            Route::get('/', [ContractorCampaignController::class, 'index'])->middleware('permission:campaigns.view');
            Route::post('/', [ContractorCampaignController::class, 'store'])->middleware('permission:campaigns.manage');
            Route::patch('{id}', [ContractorCampaignController::class, 'update'])->middleware('permission:campaigns.manage');
            Route::delete('{id}', [ContractorCampaignController::class, 'destroy'])->middleware('permission:campaigns.manage');
        });

        Route::prefix('bookings')->group(function () {
            Route::get('summary', [AdminBookingController::class, 'summary'])->middleware('permission:bookings.view');
            Route::get('/', [AdminBookingController::class, 'index'])->middleware('permission:bookings.view');
            Route::get('{id}', [AdminBookingController::class, 'show'])->middleware('permission:bookings.view');
            Route::get('{id}/contractor-options', [AdminBookingController::class, 'contractorOptions'])->middleware('permission:bookings.view');
            Route::patch('{id}/assign', [AdminBookingController::class, 'assign'])->middleware('permission:bookings.manage');
            Route::patch('{id}/status', [AdminBookingController::class, 'updateStatus'])->middleware('permission:bookings.manage');
            Route::patch('{id}/notes', [AdminBookingController::class, 'updateNotes'])->middleware('permission:bookings.manage');
            Route::post('{id}/refund', [AdminBookingController::class, 'refund'])->middleware('permission:bookings.manage');
            Route::patch('{id}/reschedule', [AdminBookingController::class, 'reschedule'])->middleware('permission:bookings.manage');
        });
        Route::get('booking-policy', [AdminBookingPolicyController::class, 'show'])->middleware('permission:bookings.manage');
        Route::put('booking-policy', [AdminBookingPolicyController::class, 'update'])->middleware('permission:bookings.manage');

        Route::get('payments', [AdminPaymentController::class, 'index'])->middleware('permission:payments.view');
        Route::get('payments/export', [AdminPaymentController::class, 'export'])->middleware('permission:payments.view');

        Route::prefix('promo-codes')->group(function () {
            Route::get('/', [AdminPromoCodeController::class, 'index'])->middleware('permission:promos.view');
            Route::post('/', [AdminPromoCodeController::class, 'store'])->middleware('permission:promos.create');
            Route::patch('{id}', [AdminPromoCodeController::class, 'update'])->middleware('permission:promos.update');
            Route::delete('{id}', [AdminPromoCodeController::class, 'destroy'])->middleware('permission:promos.delete');
        });
        Route::prefix('banners')->group(function () {
            Route::get('/', [AdminPromoBannerController::class, 'index'])->middleware('permission:banners.view');
            Route::post('/', [AdminPromoBannerController::class, 'store'])->middleware('permission:banners.create');
            Route::patch('{id}', [AdminPromoBannerController::class, 'update'])->middleware('permission:banners.update');
            Route::post('{id}/image', [AdminPromoBannerController::class, 'uploadImage'])->middleware('permission:banners.update');
            Route::delete('{id}/image', [AdminPromoBannerController::class, 'removeImage'])->middleware('permission:banners.update');
            Route::delete('{id}', [AdminPromoBannerController::class, 'destroy'])->middleware('permission:banners.delete');
        });
    });

    Route::prefix('public/join')->group(function () {
        Route::get('options', [PublicJoinController::class, 'options'])->middleware('throttle:60,1');
        Route::post('apply', [PublicJoinController::class, 'apply'])->middleware('throttle:6,1');
    });
});
