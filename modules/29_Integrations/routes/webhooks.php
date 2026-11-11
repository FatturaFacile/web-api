<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Integrations\Http\Controllers\WebhookDeliveryController;
use Modules\Integrations\Http\Controllers\WebhookEndpointController;

Route::prefix('api/v1/integrations')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:integrations'])
    ->group(function (): void {
        Route::get('webhook-events', [WebhookEndpointController::class, 'events'])->name('integrations.webhooks.events');
        Route::get('webhook-endpoints', [WebhookEndpointController::class, 'index'])->name('integrations.webhooks.endpoints.index');
        Route::post('webhook-endpoints', [WebhookEndpointController::class, 'store'])->name('integrations.webhooks.endpoints.store');
        Route::get('webhook-endpoints/{id}', [WebhookEndpointController::class, 'show'])->whereUuid('id')->name('integrations.webhooks.endpoints.show');
        Route::put('webhook-endpoints/{id}', [WebhookEndpointController::class, 'update'])->whereUuid('id')->name('integrations.webhooks.endpoints.update');
        Route::delete('webhook-endpoints/{id}', [WebhookEndpointController::class, 'destroy'])->whereUuid('id')->name('integrations.webhooks.endpoints.destroy');
        Route::post('webhook-endpoints/{id}/rotate-secret', [WebhookEndpointController::class, 'rotateSecret'])->whereUuid('id')->name('integrations.webhooks.endpoints.rotate-secret');
        Route::post('webhook-endpoints/{id}/enable', [WebhookEndpointController::class, 'enable'])->whereUuid('id')->name('integrations.webhooks.endpoints.enable');
        Route::post('webhook-endpoints/{id}/disable', [WebhookEndpointController::class, 'disable'])->whereUuid('id')->name('integrations.webhooks.endpoints.disable');
        Route::post('webhook-endpoints/{id}/test', [WebhookEndpointController::class, 'test'])->whereUuid('id')->name('integrations.webhooks.endpoints.test');
        Route::get('webhook-deliveries', [WebhookDeliveryController::class, 'index'])->name('integrations.webhooks.deliveries.index');
        Route::get('webhook-deliveries/{id}', [WebhookDeliveryController::class, 'show'])->whereUuid('id')->name('integrations.webhooks.deliveries.show');
        Route::post('webhook-deliveries/{id}/redeliver', [WebhookDeliveryController::class, 'redeliver'])->whereUuid('id')->name('integrations.webhooks.deliveries.redeliver');
    });
