<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Platform\Http\Controllers\NotificationAlertRuleController;
use Modules\Platform\Http\Controllers\NotificationChannelController;
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
            Route::get('/devices', [NotificationChannelController::class, 'devices'])->name('devices.index');
            Route::post('/devices', [NotificationChannelController::class, 'registerDevice'])->name('devices.store');
            Route::delete('/devices/{id}', [NotificationChannelController::class, 'destroyDevice'])->where('id', $uuid)->name('devices.destroy');
            Route::get('/deliveries', [NotificationChannelController::class, 'deliveries'])->name('deliveries.index');
            Route::post('/channels/telegram/link', [NotificationChannelController::class, 'linkTelegram'])->name('telegram.link');
            Route::delete('/channels/telegram', [NotificationChannelController::class, 'unlinkTelegram'])->name('telegram.unlink');
            Route::get('/realtime', [NotificationController::class, 'realtime'])->name('realtime');
            Route::get('/settings', [NotificationController::class, 'settings'])->name('settings.show');
            Route::put('/settings', [NotificationController::class, 'updateSettings'])->name('settings.update');
            Route::post('/read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
            Route::post('/{id}/read', [NotificationController::class, 'markRead'])->where('id', $uuid)->name('read');
            Route::delete('/{id}', [NotificationController::class, 'destroy'])->where('id', $uuid)->name('destroy');
        });

        Route::prefix('notification-alert-rules')->name('notification-alert-rules.')->middleware('module.access:platform')->group(function () use ($uuid): void {
            Route::get('/', [NotificationAlertRuleController::class, 'index'])->name('index');
            Route::post('/', [NotificationAlertRuleController::class, 'store'])->name('store');
            Route::put('/{id}', [NotificationAlertRuleController::class, 'update'])->where('id', $uuid)->name('update');
            Route::delete('/{id}', [NotificationAlertRuleController::class, 'destroy'])->where('id', $uuid)->name('destroy');
        });

        Route::prefix('recycle-bin')->name('recycle-bin.')->middleware('module.access:platform')->group(function () use ($uuid): void {
            Route::get('/types', [RecycleBinController::class, 'types'])->name('types');
            Route::get('/', [RecycleBinController::class, 'index'])->name('index');
            Route::post('/{type}/{id}/restore', [RecycleBinController::class, 'restore'])->where(['type' => '[a-z][a-z0-9-]*', 'id' => $uuid])->name('restore');
            Route::delete('/{type}/{id}', [RecycleBinController::class, 'forceDelete'])->where(['type' => '[a-z][a-z0-9-]*', 'id' => $uuid])->name('destroy');
        });
    });

// Telegram calls this without a session; the secret in the URL is the credential.
Route::post('api/v1/platform/telegram/webhook/{secret}', [NotificationChannelController::class, 'telegramWebhook'])->middleware('api')->name('api.v1.platform.telegram.webhook');
