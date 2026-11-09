<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\FiscalRetail\Http\Controllers\CommercialDocumentController;
use Modules\FiscalRetail\Http\Controllers\CorrispettiviController;

Route::prefix('api/v1/fiscal-retail')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:fiscal_retail'])
    ->group(function (): void {
        Route::get('commercial-documents', [CommercialDocumentController::class, 'index'])->name('fiscal-retail.documents.index');
        Route::post('commercial-documents', [CommercialDocumentController::class, 'store'])->name('fiscal-retail.documents.store');
        Route::get('commercial-documents/{id}', [CommercialDocumentController::class, 'show'])->name('fiscal-retail.documents.show');
        Route::post('commercial-documents/{id}/retransmit', [CommercialDocumentController::class, 'retransmit'])->whereUuid('id')->name('fiscal-retail.documents.retransmit');
        Route::post('commercial-documents/{id}/void', [CommercialDocumentController::class, 'void'])->name('fiscal-retail.documents.void');
        Route::post('commercial-documents/{id}/return', [CommercialDocumentController::class, 'return'])->name('fiscal-retail.documents.return');

        Route::get('corrispettivi/daily/{date}', [CorrispettiviController::class, 'daily'])->name('fiscal-retail.corrispettivi.daily');
        Route::post('corrispettivi/daily/{date}/close', [CorrispettiviController::class, 'close'])->name('fiscal-retail.corrispettivi.close');
        Route::get('corrispettivi/register', [CorrispettiviController::class, 'register'])->name('fiscal-retail.corrispettivi.register');
    });
