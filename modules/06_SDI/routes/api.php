<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\InvoiceSdiController;
use App\Http\Controllers\Api\V1\SdiWebhookController;
use Illuminate\Support\Facades\Route;
use Modules\SDI\Http\Controllers\FpaDocumentController;
use Modules\SDI\Http\Controllers\SdiChannelController;
use Modules\SDI\Http\Controllers\SdiConfigurationController;
use Modules\SDI\Http\Controllers\SdiGatewayWebhookController;
use Modules\SDI\Http\Controllers\SdiInvoiceController;
use Modules\SDI\Http\Controllers\SdiPassiveImportController;
use Modules\SDI\Http\Controllers\SdiSupplierIntegrationController;
use Modules\SDI\Http\Controllers\SdiTransmissionController;

Route::prefix('api/v1/settings/sdi-channel')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:system'])
    ->group(function (): void {
        Route::get('/', [SdiChannelController::class, 'show'])->name('sdi.channel.show');
        Route::put('/', [SdiChannelController::class, 'update'])->name('sdi.channel.update');
        Route::post('/poll', [SdiChannelController::class, 'poll'])->name('sdi.channel.poll');
        Route::get('/quarantine', [SdiChannelController::class, 'quarantine'])->name('sdi.channel.quarantine');
        Route::post('/quarantine/{log}/retry', [SdiChannelController::class, 'retryQuarantined'])->whereUuid('log')->name('sdi.channel.quarantine.retry');
    });

Route::post('api/v1/webhooks/sdi/gateways/{tenant}', SdiGatewayWebhookController::class)
    ->middleware(['api', 'throttle:120,1'])
    ->whereUuid('tenant')
    ->name('sdi.gateway-webhook');

Route::prefix('api/v1/sdi')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:invoicing'])
    ->group(function (): void {
        Route::get('transmissions', [SdiTransmissionController::class, 'index'])->name('sdi.transmissions.index');
        Route::post('invoices/{id}/generate-xml', [SdiInvoiceController::class, 'generateXml'])->whereUuid('id')->name('sdi.invoices.generate-xml');
        Route::post('invoices/{id}/send', [InvoiceSdiController::class, 'send'])->whereUuid('id')->name('sdi.invoices.send');
        Route::get('invoices/{id}/preview-html', [SdiInvoiceController::class, 'previewHtml'])->whereUuid('id')->name('sdi.invoices.preview-html');
        Route::get('invoices/{id}/download-xml', [SdiInvoiceController::class, 'downloadXml'])->whereUuid('id')->name('sdi.invoices.download-xml');
        Route::get('invoices/{id}/notifications', [InvoiceSdiController::class, 'logs'])->whereUuid('id')->name('sdi.invoices.notifications');
        Route::post('supplier-invoices/{invoice}/generate-integration', SdiSupplierIntegrationController::class)->whereUuid('invoice')->name('sdi.supplier-invoices.generate-integration');
    });

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:invoicing'])
    ->group(function (): void {
        Route::get('fpa-documents', [FpaDocumentController::class, 'index'])->name('fpa-documents.index');
        Route::get('fpa-documents/{id}', [FpaDocumentController::class, 'show'])->whereUuid('id')->name('fpa-documents.show');
        Route::get('fpa-documents/{id}/render', [FpaDocumentController::class, 'render'])->whereUuid('id')->name('fpa-documents.render');
        Route::post('fpa-documents/{id}/resubmit', [FpaDocumentController::class, 'resubmit'])->whereUuid('id')->name('fpa-documents.resubmit');
        Route::post('fpa-documents/{id}/resubmit-and-send', [FpaDocumentController::class, 'resubmitAndSend'])->whereUuid('id')->name('fpa-documents.resubmit-and-send');
        Route::post('fpa-documents/{id}/simulate', [FpaDocumentController::class, 'simulate'])->whereUuid('id')->name('fpa-documents.simulate');
        Route::post('sdi/invoices/{id}/preflight', [FpaDocumentController::class, 'preflight'])->whereUuid('id')->name('sdi.invoices.preflight');
    });

Route::prefix('api/v1/settings/sdi')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:system'])
    ->group(function (): void {
        Route::get('/', [SdiConfigurationController::class, 'show'])->name('sdi.configuration.show');
        Route::put('/', [SdiConfigurationController::class, 'update'])->name('sdi.configuration.update');
    });

Route::post('api/v1/sdi/passive/import', SdiPassiveImportController::class)
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:procurement'])
    ->name('sdi.passive.import');

Route::post('api/v1/sdi/webhook', SdiWebhookController::class)
    ->middleware(['api', 'throttle:120,1'])
    ->name('sdi.webhook');
