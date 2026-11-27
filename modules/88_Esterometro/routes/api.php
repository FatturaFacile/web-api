<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Esterometro\Http\Controllers\DashboardController;
use Modules\Esterometro\Http\Controllers\RuleController;
use Modules\Esterometro\Http\Controllers\SelfInvoiceController;
use Modules\Esterometro\Http\Controllers\SettingsController;

Route::middleware(['api', 'auth:sanctum', 'tenant.access'])
    ->prefix('api/v1/esterometro')
    ->group(function (): void {
        Route::get('dashboard', [DashboardController::class, 'index'])
            ->middleware('module.access:esterometro');

        Route::post('classify-preview', [SelfInvoiceController::class, 'classifyPreview'])
            ->middleware('module.access:esterometro');

        Route::get('self-invoices', [SelfInvoiceController::class, 'index'])
            ->middleware('module.access:esterometro');
        Route::post('self-invoices', [SelfInvoiceController::class, 'store'])
            ->middleware('module.access:esterometro');
        Route::post('self-invoices/from-passive/{passiveInvoiceId}', [SelfInvoiceController::class, 'generateFromPassive'])
            ->middleware('module.access:esterometro');
        Route::post('self-invoices/batch-send', [SelfInvoiceController::class, 'batchSend'])
            ->middleware('module.access:esterometro');

        Route::get('self-invoices/{id}', [SelfInvoiceController::class, 'show'])
            ->middleware('module.access:esterometro');
        Route::put('self-invoices/{id}', [SelfInvoiceController::class, 'update'])
            ->middleware('module.access:esterometro');
        Route::post('self-invoices/{id}/validate', [SelfInvoiceController::class, 'validateXml'])
            ->middleware('module.access:esterometro');
        Route::post('self-invoices/{id}/reconcile-vat', [SelfInvoiceController::class, 'reconcileVat'])
            ->middleware('module.access:esterometro');
        Route::post('self-invoices/{id}/send-sdi', [SelfInvoiceController::class, 'sendToSdi'])
            ->middleware('module.access:esterometro');
        Route::post('self-invoices/{id}/sdi-notification', [SelfInvoiceController::class, 'recordSdiNotification'])
            ->middleware('module.access:esterometro');
        Route::get('self-invoices/{id}/xml', [SelfInvoiceController::class, 'downloadXml'])
            ->middleware('module.access:esterometro');

        Route::get('rules', [RuleController::class, 'index'])
            ->middleware('module.access:esterometro');
        Route::post('rules', [RuleController::class, 'store'])
            ->middleware('module.access:esterometro');
        Route::put('rules/{id}', [RuleController::class, 'update'])
            ->middleware('module.access:esterometro');
        Route::delete('rules/{id}', [RuleController::class, 'destroy'])
            ->middleware('module.access:esterometro');

        Route::get('settings', [SettingsController::class, 'show'])
            ->middleware('module.access:esterometro');
        Route::put('settings', [SettingsController::class, 'update'])
            ->middleware('module.access:esterometro');
    });
