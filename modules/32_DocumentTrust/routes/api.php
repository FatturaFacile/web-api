<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\DocumentTrust\Http\Controllers\DocumentTrustController;
use Modules\DocumentTrust\Http\Controllers\PreservationController;

Route::prefix('api/v1/document-trust')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:document_trust'])
    ->group(function (): void {
        Route::post('signatures', [DocumentTrustController::class, 'sign'])->name('document-trust.sign');
        Route::get('signatures', [DocumentTrustController::class, 'signatures'])->name('document-trust.signatures');
        Route::post('signatures/{id}/verify', [DocumentTrustController::class, 'verify'])->whereUuid('id')->name('document-trust.verify');
        Route::post('preservation', [DocumentTrustController::class, 'preserve'])->name('document-trust.preserve');
        Route::get('preservation', [DocumentTrustController::class, 'preservationRecords'])->name('document-trust.preservation');
    });

Route::prefix('api/v1/document-trust/settings')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:integrations'])
    ->group(function (): void {
        Route::get('/', [DocumentTrustController::class, 'settings'])->name('document-trust.settings.show');
        Route::put('/', [DocumentTrustController::class, 'updateSettings'])->name('document-trust.settings.update');
    });

Route::prefix('api/v1/document-trust')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:document_trust'])
    ->group(function (): void {
        Route::get('preservation-packages', [PreservationController::class, 'index'])->name('document-trust.preservation-packages.index');
        Route::post('preservation-packages', [PreservationController::class, 'store'])->name('document-trust.preservation-packages.store');
        Route::get('preservation-packages/{id}', [PreservationController::class, 'show'])->whereUuid('id')->name('document-trust.preservation-packages.show');
        Route::delete('preservation-packages/{id}', [PreservationController::class, 'destroy'])->whereUuid('id')->name('document-trust.preservation-packages.destroy');
        Route::post('preservation-packages/{id}/items', [PreservationController::class, 'addItem'])->whereUuid('id')->name('document-trust.preservation-packages.items.store');
        Route::delete('preservation-packages/{id}/items/{itemId}', [PreservationController::class, 'removeItem'])->whereUuid(['id', 'itemId'])->name('document-trust.preservation-packages.items.destroy');
        Route::post('preservation-packages/{id}/close', [PreservationController::class, 'close'])->whereUuid('id')->name('document-trust.preservation-packages.close');
        Route::post('preservation-packages/{id}/verify', [PreservationController::class, 'verify'])->whereUuid('id')->name('document-trust.preservation-packages.verify');
        Route::get('preservation-packages/{id}/export', [PreservationController::class, 'export'])->whereUuid('id')->name('document-trust.preservation-packages.export');
        Route::get('preservation-items', [PreservationController::class, 'search'])->name('document-trust.preservation-items.search');
        Route::get('preservation-settings', [PreservationController::class, 'settings'])->name('document-trust.preservation-settings.show');
        Route::put('preservation-settings', [PreservationController::class, 'updateSettings'])->name('document-trust.preservation-settings.update');
        Route::get('preservation-access-logs', [PreservationController::class, 'accessLogs'])->name('document-trust.preservation-access-logs.index');
    });
