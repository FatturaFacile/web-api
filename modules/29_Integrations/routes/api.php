<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Integrations\Http\Controllers\ApiKeyController;
use Modules\Integrations\Http\Controllers\PaymentLinkController;
use Modules\Integrations\Http\Controllers\PaymentLinkWebhookController;

Route::prefix('api/v1/integrations')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:integrations'])
    ->group(function (): void {
        Route::get('api-keys', [ApiKeyController::class, 'index'])->name('integrations.api-keys.index');
        Route::post('api-keys', [ApiKeyController::class, 'store'])->name('integrations.api-keys.store');
        Route::post('api-keys/{id}/rotate', [ApiKeyController::class, 'rotate'])->whereUuid('id')->name('integrations.api-keys.rotate');
        Route::delete('api-keys/{id}', [ApiKeyController::class, 'revoke'])->name('integrations.api-keys.revoke');
        Route::get('payment-gateway', [PaymentLinkController::class, 'settings'])->name('integrations.payment-gateway.show');
        Route::put('payment-gateway', [PaymentLinkController::class, 'updateSettings'])->name('integrations.payment-gateway.update');
    });

Route::prefix('api/v1/payment-links')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:payment_links'])
    ->group(function (): void {
        Route::get('/', [PaymentLinkController::class, 'index'])->name('payment-links.index');
        Route::post('/', [PaymentLinkController::class, 'store'])->name('payment-links.store');
        Route::get('/{id}', [PaymentLinkController::class, 'show'])->whereUuid('id')->name('payment-links.show');
        Route::post('/{id}/cancel', [PaymentLinkController::class, 'cancel'])->whereUuid('id')->name('payment-links.cancel');
    });

Route::post('api/v1/webhooks/payment-links/{tenant}', PaymentLinkWebhookController::class)
    ->middleware(['api', 'throttle:120,1'])
    ->whereUuid('tenant')
    ->name('payment-links.webhook');
