<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\FiscalRetail\Http\Controllers\CashMovementController;
use Modules\FiscalRetail\Http\Controllers\CommercialDocumentController;
use Modules\FiscalRetail\Http\Controllers\CorrispettiviController;
use Modules\FiscalRetail\Http\Controllers\PromotionController;
use Modules\FiscalRetail\Http\Controllers\ReturnController;
use Modules\FiscalRetail\Http\Controllers\SessionReportController;
use Modules\FiscalRetail\Http\Controllers\TicketController;

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
        Route::prefix('pos')->whereUuid('id')->group(function (): void {
            Route::get('promotions', [PromotionController::class, 'index'])->name('fiscal-retail.pos.promotions.index');
            Route::post('promotions', [PromotionController::class, 'store'])->name('fiscal-retail.pos.promotions.store');
            Route::post('promotions/preview', [PromotionController::class, 'preview'])->name('fiscal-retail.pos.promotions.preview');
            Route::get('promotions/{id}', [PromotionController::class, 'show'])->name('fiscal-retail.pos.promotions.show');
            Route::put('promotions/{id}', [PromotionController::class, 'update'])->name('fiscal-retail.pos.promotions.update');
            Route::delete('promotions/{id}', [PromotionController::class, 'destroy'])->name('fiscal-retail.pos.promotions.destroy');

            Route::post('tickets', [TicketController::class, 'store'])->name('fiscal-retail.pos.tickets.store');
            Route::get('tickets/{id}', [TicketController::class, 'show'])->name('fiscal-retail.pos.tickets.show');

            Route::get('sessions/{id}/cash-movements', [CashMovementController::class, 'index'])->name('fiscal-retail.pos.cash-movements.index');
            Route::post('sessions/{id}/cash-movements', [CashMovementController::class, 'store'])->name('fiscal-retail.pos.cash-movements.store');
            Route::get('sessions/{id}/x-report', [SessionReportController::class, 'x'])->name('fiscal-retail.pos.x-report');
            Route::get('sessions/{id}/z-report', [SessionReportController::class, 'z'])->name('fiscal-retail.pos.z-report');
            Route::get('z-reports', [SessionReportController::class, 'index'])->name('fiscal-retail.pos.z-reports.index');

            Route::post('sales/{id}/returns', [ReturnController::class, 'store'])->name('fiscal-retail.pos.returns.store');
            Route::post('sales/{id}/refund', [ReturnController::class, 'refundSale'])->name('fiscal-retail.pos.sales.refund');
            Route::get('returns', [ReturnController::class, 'index'])->name('fiscal-retail.pos.returns.index');
            Route::get('returns/{id}', [ReturnController::class, 'show'])->name('fiscal-retail.pos.returns.show');
        });

        Route::get('corrispettivi/register', [CorrispettiviController::class, 'register'])->name('fiscal-retail.corrispettivi.register');
    });
