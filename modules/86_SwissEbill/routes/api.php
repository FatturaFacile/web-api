<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SwissEbill\Http\Controllers\EbillSettingsController;
use Modules\SwissEbill\Http\Controllers\EbillTransmissionController;
use Modules\SwissEbill\Http\Controllers\EbillWebhookController;
use Modules\SwissEbill\Http\Controllers\ParticipantController;

/*
|--------------------------------------------------------------------------
| Swiss eBill: direct SIX Paynet / PostFinance transmission, participant directory, lifecycle and camt.054 settlement (Mod 025 / Issue #283)
|--------------------------------------------------------------------------
*/
Route::prefix('api/v1/swiss-ebill')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:swiss_ebill'])
    ->group(function (): void {
        Route::get('settings', [EbillSettingsController::class, 'show'])->name('swiss-ebill.settings.show');
        Route::put('settings', [EbillSettingsController::class, 'update'])->name('swiss-ebill.settings.update');
        Route::post('settings/test-connection', [EbillSettingsController::class, 'testConnection'])->name('swiss-ebill.settings.test-connection');
        Route::post('settings/rotate-webhook-secret', [EbillSettingsController::class, 'rotateWebhookSecret'])->name('swiss-ebill.settings.rotate-webhook-secret');

        Route::post('participants/lookup', [ParticipantController::class, 'lookup'])->name('swiss-ebill.participants.lookup');
        Route::post('customers/ebill/bulk-verify', [ParticipantController::class, 'bulkVerify'])->name('swiss-ebill.customers.bulk-verify');
        Route::post('customers/{id}/ebill/sync', [ParticipantController::class, 'sync'])->whereUuid('id')->name('swiss-ebill.customers.sync');
        Route::post('customers/{id}/ebill/link', [ParticipantController::class, 'link'])->whereUuid('id')->name('swiss-ebill.customers.link');

        Route::get('dashboard', [EbillTransmissionController::class, 'dashboard'])->name('swiss-ebill.dashboard');
        Route::get('transmissions', [EbillTransmissionController::class, 'index'])->name('swiss-ebill.transmissions.index');
        Route::get('transmissions/{id}', [EbillTransmissionController::class, 'show'])->whereUuid('id')->name('swiss-ebill.transmissions.show');
        Route::get('transmissions/{id}/payload', [EbillTransmissionController::class, 'payload'])->whereUuid('id')->name('swiss-ebill.transmissions.payload');
        Route::post('transmissions/{id}/revise', [EbillTransmissionController::class, 'revise'])->whereUuid('id')->name('swiss-ebill.transmissions.revise');
        Route::post('transmissions/{id}/retry', [EbillTransmissionController::class, 'retry'])->whereUuid('id')->name('swiss-ebill.transmissions.retry');
        Route::post('transmissions/{id}/cancel', [EbillTransmissionController::class, 'cancel'])->whereUuid('id')->name('swiss-ebill.transmissions.cancel');

        Route::get('invoices/{id}', [EbillTransmissionController::class, 'invoice'])->whereUuid('id')->name('swiss-ebill.invoices.show');
        Route::post('invoices/{id}/send', [EbillTransmissionController::class, 'send'])->whereUuid('id')->name('swiss-ebill.invoices.send');
    });

// Public: the signature of the call (HMAC-SHA256, JWS or the pinned client certificate) is the credential, the unguessable slug selects the company.
Route::post('api/v1/webhooks/swiss-ebill/{tenantSlug}', EbillWebhookController::class)
    ->middleware(['api', 'throttle:300,1'])
    ->where('tenantSlug', '[A-Za-z0-9_-]{16,80}')
    ->name('webhooks.swiss-ebill');
