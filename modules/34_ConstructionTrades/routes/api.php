<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ConstructionTrades\Http\Controllers\BidController;
use Modules\ConstructionTrades\Http\Controllers\BidProgressController;
use Modules\ConstructionTrades\Http\Controllers\ConstructionSiteController;
use Modules\ConstructionTrades\Http\Controllers\PriceListController;
use Modules\ConstructionTrades\Http\Controllers\WorkCycleController;

Route::prefix('api/v1/construction-trades')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:construction_trades'])
    ->name('api.v1.construction-trades.')
    ->where(['id' => '[0-9a-fA-F-]{36}', 'positionId' => '[0-9a-fA-F-]{36}'])
    ->group(function (): void {
        Route::get('/price-lists', [PriceListController::class, 'index'])->name('price-lists.index');
        Route::post('/price-lists', [PriceListController::class, 'store'])->name('price-lists.store');
        Route::get('/price-lists/{id}', [PriceListController::class, 'show'])->name('price-lists.show');
        Route::put('/price-lists/{id}', [PriceListController::class, 'update'])->name('price-lists.update');
        Route::post('/price-lists/{id}/items', [PriceListController::class, 'storeItem'])->name('price-lists.items.store');
        Route::post('/price-lists/{id}/import', [PriceListController::class, 'import'])->name('price-lists.import');

        Route::get('/work-cycles', [WorkCycleController::class, 'index'])->name('work-cycles.index');
        Route::post('/work-cycles', [WorkCycleController::class, 'store'])->name('work-cycles.store');
        Route::get('/work-cycles/{id}', [WorkCycleController::class, 'show'])->name('work-cycles.show');

        Route::get('/bids', [BidController::class, 'index'])->name('bids.index');
        Route::post('/bids', [BidController::class, 'store'])->name('bids.store');
        Route::get('/bids/{id}', [BidController::class, 'show'])->name('bids.show');
        Route::put('/bids/{id}', [BidController::class, 'update'])->name('bids.update');
        Route::post('/bids/{id}/status', [BidController::class, 'status'])->name('bids.status');
        Route::post('/bids/{id}/positions', [BidController::class, 'storePosition'])->name('bids.positions.store');
        Route::delete('/bids/{id}/positions/{positionId}', [BidController::class, 'destroyPosition'])->name('bids.positions.destroy');
        Route::get('/bids/{id}/tender-specification', [BidController::class, 'tenderSpecification'])->name('bids.tender-specification');

        Route::post('/bids/{id}/measurements', [BidProgressController::class, 'storeMeasurement'])->name('bids.measurements.store');
        Route::get('/bids/{id}/settlement', [BidProgressController::class, 'settlement'])->name('bids.settlement');
        Route::post('/bids/{id}/settlement/approve', [BidProgressController::class, 'approveSettlement'])->name('bids.settlement.approve');
        Route::get('/bids/{id}/billings', [BidProgressController::class, 'billings'])->name('bids.billings.index');
        Route::post('/bids/{id}/billings', [BidProgressController::class, 'storeBilling'])->name('bids.billings.store');
        Route::get('/bids/{id}/site-costs', [BidProgressController::class, 'siteCosts'])->name('bids.site-costs.index');
        Route::post('/bids/{id}/site-costs', [BidProgressController::class, 'storeSiteCost'])->name('bids.site-costs.store');
        Route::get('/bids/{id}/budget-control', [BidProgressController::class, 'budgetControl'])->name('bids.budget-control');
    });

// Construction sites referenced by progress billings (SAL) and bids. The list is GET /api/v1/construction/sites.
Route::prefix('api/v1/construction')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:construction'])
    ->name('api.v1.construction.')
    ->where(['id' => '[0-9a-fA-F-]{36}'])
    ->group(function (): void {
        Route::post('/sites', [ConstructionSiteController::class, 'store'])->name('sites.store');
        Route::get('/sites/{id}', [ConstructionSiteController::class, 'show'])->name('sites.show');
        Route::put('/sites/{id}', [ConstructionSiteController::class, 'update'])->name('sites.update');
        Route::patch('/sites/{id}', [ConstructionSiteController::class, 'update'])->name('sites.patch');
        Route::delete('/sites/{id}', [ConstructionSiteController::class, 'destroy'])->name('sites.destroy');
    });
