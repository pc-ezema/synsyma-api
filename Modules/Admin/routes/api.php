<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Http\Controllers\AccessControlController;
use Modules\Admin\Http\Controllers\AccountDeletionController;
use Modules\Admin\Http\Controllers\AdminChatController;
use Modules\Admin\Http\Controllers\AdminController;
use Modules\Admin\Http\Controllers\AuthController;
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
    });
});