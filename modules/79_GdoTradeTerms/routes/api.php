<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\GdoTradeTerms\Http\Controllers\AccrualController;
use Modules\GdoTradeTerms\Http\Controllers\ContractController;
use Modules\GdoTradeTerms\Http\Controllers\DeliveryPointController;
use Modules\GdoTradeTerms\Http\Controllers\LookupController;
use Modules\GdoTradeTerms\Http\Controllers\PromotionController;
use Modules\GdoTradeTerms\Http\Controllers\ReportController;
use Modules\GdoTradeTerms\Http\Controllers\ScaleController;
use Modules\GdoTradeTerms\Http\Controllers\SellOutController;
use Modules\GdoTradeTerms\Http\Controllers\SettlementController;

/*
|--------------------------------------------------------------------------
| GDO trade terms (Mod 231 / Issue #477)
|--------------------------------------------------------------------------
| Framework contracts with retail chains and buying groups (Italy and Switzerland), on-invoice discounts, tiered year-end rebates
| (PFA / Jahresrückvergütung, retroactive or progressive), sell-in / sell-out trade promotions, monthly accruals and the settlement
| (credit note or reconciliation of the promotional invoice of the retailer).
*/
Route::prefix('api/v1/gdo-trade-terms')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:gdo_trade_terms'])
    ->group(function (): void {
        Route::get('lookups', [LookupController::class, 'lookups'])->name('gdo-trade-terms.lookups');
        Route::get('products', [LookupController::class, 'products'])->name('gdo-trade-terms.products');
        Route::get('dashboard', [ReportController::class, 'dashboard'])->name('gdo-trade-terms.dashboard');
        Route::get('net-net-margin', [ReportController::class, 'margin'])->name('gdo-trade-terms.net-net-margin');

        Route::get('contracts', [ContractController::class, 'index'])->name('gdo-trade-terms.contracts.index');
        Route::post('contracts', [ContractController::class, 'store'])->name('gdo-trade-terms.contracts.store');
        Route::get('contracts/{id}', [ContractController::class, 'show'])->whereUuid('id')->name('gdo-trade-terms.contracts.show');
        Route::put('contracts/{id}', [ContractController::class, 'update'])->whereUuid('id')->name('gdo-trade-terms.contracts.update');
        Route::delete('contracts/{id}', [ContractController::class, 'destroy'])->whereUuid('id')->name('gdo-trade-terms.contracts.destroy');
        Route::post('contracts/{id}/transition', [ContractController::class, 'transition'])->whereUuid('id')->name('gdo-trade-terms.contracts.transition');
        Route::get('contracts/{id}/progress', [ContractController::class, 'progress'])->whereUuid('id')->name('gdo-trade-terms.contracts.progress');

        Route::post('contracts/{id}/scales', [ScaleController::class, 'store'])->whereUuid('id')->name('gdo-trade-terms.scales.store');
        Route::put('contracts/{id}/scales/{scaleId}', [ScaleController::class, 'update'])->whereUuid(['id', 'scaleId'])->name('gdo-trade-terms.scales.update');
        Route::delete('contracts/{id}/scales/{scaleId}', [ScaleController::class, 'destroy'])->whereUuid(['id', 'scaleId'])->name('gdo-trade-terms.scales.destroy');
        Route::post('scale-simulations', [ScaleController::class, 'simulate'])->name('gdo-trade-terms.scales.simulate');

        Route::get('promotions', [PromotionController::class, 'index'])->name('gdo-trade-terms.promotions.index');
        Route::post('contracts/{id}/promotions', [PromotionController::class, 'store'])->whereUuid('id')->name('gdo-trade-terms.promotions.store');
        Route::put('contracts/{id}/promotions/{promotionId}', [PromotionController::class, 'update'])->whereUuid(['id', 'promotionId'])->name('gdo-trade-terms.promotions.update');
        Route::post('contracts/{id}/promotions/{promotionId}/transition', [PromotionController::class, 'transition'])->whereUuid(['id', 'promotionId'])->name('gdo-trade-terms.promotions.transition');
        Route::delete('contracts/{id}/promotions/{promotionId}', [PromotionController::class, 'destroy'])->whereUuid(['id', 'promotionId'])->name('gdo-trade-terms.promotions.destroy');

        Route::get('delivery-points', [DeliveryPointController::class, 'index'])->name('gdo-trade-terms.delivery-points.index');
        Route::post('delivery-points', [DeliveryPointController::class, 'store'])->name('gdo-trade-terms.delivery-points.store');
        Route::put('delivery-points/{id}', [DeliveryPointController::class, 'update'])->whereUuid('id')->name('gdo-trade-terms.delivery-points.update');
        Route::delete('delivery-points/{id}', [DeliveryPointController::class, 'destroy'])->whereUuid('id')->name('gdo-trade-terms.delivery-points.destroy');
        Route::post('invoice-delivery-points', [DeliveryPointController::class, 'assignInvoices'])->name('gdo-trade-terms.invoice-delivery-points');

        Route::get('sell-out-reports', [SellOutController::class, 'index'])->name('gdo-trade-terms.sell-out.index');
        Route::post('contracts/{id}/sell-out-reports', [SellOutController::class, 'import'])->whereUuid('id')->name('gdo-trade-terms.sell-out.import');

        Route::get('accrual-runs', [AccrualController::class, 'index'])->name('gdo-trade-terms.accrual-runs.index');
        Route::post('accrual-runs/preview', [AccrualController::class, 'preview'])->name('gdo-trade-terms.accrual-runs.preview');
        Route::post('accrual-runs', [AccrualController::class, 'store'])->name('gdo-trade-terms.accrual-runs.store');
        Route::get('accrual-runs/{id}', [AccrualController::class, 'show'])->whereUuid('id')->name('gdo-trade-terms.accrual-runs.show');
        Route::post('accrual-runs/{id}/reverse', [AccrualController::class, 'reverse'])->whereUuid('id')->name('gdo-trade-terms.accrual-runs.reverse');

        Route::get('settlements', [SettlementController::class, 'index'])->name('gdo-trade-terms.settlements.index');
        Route::post('settlements', [SettlementController::class, 'store'])->name('gdo-trade-terms.settlements.store');
        Route::get('settlements/{id}', [SettlementController::class, 'show'])->whereUuid('id')->name('gdo-trade-terms.settlements.show');
        Route::post('settlements/{id}/credit-note', [SettlementController::class, 'creditNote'])->whereUuid('id')->name('gdo-trade-terms.settlements.credit-note');
        Route::post('settlements/{id}/promo-invoice', [SettlementController::class, 'promoInvoice'])->whereUuid('id')->name('gdo-trade-terms.settlements.promo-invoice');
        Route::post('settlements/{id}/resolve-dispute', [SettlementController::class, 'resolveDispute'])->whereUuid('id')->name('gdo-trade-terms.settlements.resolve-dispute');
        Route::post('settlements/{id}/cancel', [SettlementController::class, 'cancel'])->whereUuid('id')->name('gdo-trade-terms.settlements.cancel');
    });
