<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\DocumentTrust\Http\Controllers\DocumentTrustController;

Route::prefix('api/v1/document-trust')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:document_trust'])
    ->group(function (): void {
        Route::post('signatures', [DocumentTrustController::class, 'sign'])->name('document-trust.sign');
        Route::get('signatures', [DocumentTrustController::class, 'signatures'])->name('document-trust.signatures');
        Route::post('preservation', [DocumentTrustController::class, 'preserve'])->name('document-trust.preserve');
        Route::get('preservation', [DocumentTrustController::class, 'preservationRecords'])->name('document-trust.preservation');
    });

Route::prefix('api/v1/document-trust/settings')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:integrations'])
    ->group(function (): void {
        Route::get('/', [DocumentTrustController::class, 'settings'])->name('document-trust.settings.show');
        Route::put('/', [DocumentTrustController::class, 'updateSettings'])->name('document-trust.settings.update');
    });
