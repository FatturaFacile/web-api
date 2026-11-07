<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SDI\Http\Controllers\SdiChannelController;
use Modules\SDI\Http\Controllers\SdiGatewayWebhookController;

Route::prefix('api/v1/settings/sdi-channel')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:system'])
    ->group(function (): void {
        Route::get('/', [SdiChannelController::class, 'show'])->name('sdi.channel.show');
        Route::put('/', [SdiChannelController::class, 'update'])->name('sdi.channel.update');
        Route::post('/poll', [SdiChannelController::class, 'poll'])->name('sdi.channel.poll');
    });

Route::post('api/v1/webhooks/sdi/gateways/{tenant}', SdiGatewayWebhookController::class)
    ->middleware(['api', 'throttle:120,1'])
    ->whereUuid('tenant')
    ->name('sdi.gateway-webhook');
