<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Platform\Http\Controllers\NotificationController;
use Modules\Platform\Http\Controllers\RecycleBinController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Platform. The notification centre belongs to the signed-in user (no module rule: every member reads and clears their own
| notifications). The recycle bin is limited to owners and administrators by module.access:platform.
*/
Route::prefix('api/v1/platform')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])
    ->name('api.v1.platform.')
    ->group(function () use ($uuid): void {
        Route::prefix('notifications')->name('notifications.')->group(function () use ($uuid): void {
            Route::get('/', [NotificationController::class, 'index'])->name('index');
            Route::get('/preferences', [NotificationController::class, 'preferences'])->name('preferences.show');
            Route::put('/preferences', [NotificationController::class, 'updatePreferences'])->name('preferences.update');
            Route::get('/realtime', [NotificationController::class, 'realtime'])->name('realtime');
            Route::get('/settings', [NotificationController::class, 'settings'])->name('settings.show');
            Route::put('/settings', [NotificationController::class, 'updateSettings'])->name('settings.update');
            Route::post('/read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
            Route::post('/{id}/read', [NotificationController::class, 'markRead'])->where('id', $uuid)->name('read');
            Route::delete('/{id}', [NotificationController::class, 'destroy'])->where('id', $uuid)->name('destroy');
        });

        Route::prefix('recycle-bin')->name('recycle-bin.')->middleware('module.access:platform')->group(function () use ($uuid): void {
            Route::get('/types', [RecycleBinController::class, 'types'])->name('types');
            Route::get('/', [RecycleBinController::class, 'index'])->name('index');
            Route::post('/{type}/{id}/restore', [RecycleBinController::class, 'restore'])->where(['type' => '[a-z][a-z0-9-]*', 'id' => $uuid])->name('restore');
            Route::delete('/{type}/{id}', [RecycleBinController::class, 'forceDelete'])->where(['type' => '[a-z][a-z0-9-]*', 'id' => $uuid])->name('destroy');
        });
    });
