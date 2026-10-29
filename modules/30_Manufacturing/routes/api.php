<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Manufacturing\Http\Controllers\BomController;
use Modules\Manufacturing\Http\Controllers\MrpController;
use Modules\Manufacturing\Http\Controllers\ProductionOrderController;
use Modules\Manufacturing\Http\Controllers\QualityController;
use Modules\Manufacturing\Http\Controllers\ShopFloorController;

Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1/manufacturing')->group(function (): void {
    // BOMs
    Route::get('/boms', [BomController::class, 'index'])->name('mfg.boms.index');
    Route::post('/boms', [BomController::class, 'store'])->name('mfg.boms.store');
    Route::get('/boms/{id}', [BomController::class, 'show'])->name('mfg.boms.show');
    Route::get('/boms/{id}/explode', [BomController::class, 'explode'])->name('mfg.boms.explode');
    Route::post('/boms/{id}/revise', [BomController::class, 'revise'])->name('mfg.boms.revise');

    // Production Orders
    Route::get('/production-orders', [ProductionOrderController::class, 'index'])->name('mfg.production_orders.index');
    Route::post('/production-orders', [ProductionOrderController::class, 'store'])->name('mfg.production_orders.store');
    Route::get('/production-orders/{id}', [ProductionOrderController::class, 'show'])->name('mfg.production_orders.show');
    Route::post('/production-orders/{id}/release', [ProductionOrderController::class, 'release'])->name('mfg.production_orders.release');
    Route::post('/production-orders/{id}/start', [ProductionOrderController::class, 'start'])->name('mfg.production_orders.start');
    Route::post('/production-orders/{id}/complete', [ProductionOrderController::class, 'complete'])->name('mfg.production_orders.complete');
    Route::post('/production-orders/{id}/close', [ProductionOrderController::class, 'close'])->name('mfg.production_orders.close');
    Route::post('/production-orders/{id}/cancel', [ProductionOrderController::class, 'cancel'])->name('mfg.production_orders.cancel');
    Route::get('/production-orders/{id}/cost-rollup', [ProductionOrderController::class, 'costRollup'])->name('mfg.production_orders.cost_rollup');

    // MRP Engine
    Route::get('/mrp/runs', [MrpController::class, 'index'])->name('mfg.mrp.runs.index');
    Route::post('/mrp/runs', [MrpController::class, 'run'])->name('mfg.mrp.runs.store');
    Route::get('/mrp/planned-orders', [MrpController::class, 'plannedOrders'])->name('mfg.mrp.planned_orders');
    Route::get('/mrp/shortages', [MrpController::class, 'shortages'])->name('mfg.mrp.shortages');
    Route::post('/mrp/planned-orders/{id}/convert', [MrpController::class, 'convertPlannedOrder'])->name('mfg.mrp.planned_orders.convert');

    // Shop Floor Control
    Route::post('/shop-floor/executions', [ShopFloorController::class, 'recordExecution'])->name('mfg.shop_floor.record_execution');
    Route::post('/shop-floor/executions/{id}/complete', [ShopFloorController::class, 'completeExecution'])->name('mfg.shop_floor.complete_execution');
    Route::post('/shop-floor/executions/{id}/downtime', [ShopFloorController::class, 'recordDowntime'])->name('mfg.shop_floor.record_downtime');

    // Quality Management
    Route::get('/quality/inspections', [QualityController::class, 'inspections'])->name('mfg.quality.inspections');
    Route::post('/quality/inspections', [QualityController::class, 'recordInspection'])->name('mfg.quality.record_inspection');
    Route::get('/quality/non-conformances', [QualityController::class, 'nonConformances'])->name('mfg.quality.non_conformances');
});

// Alias for /api/v1/mfg
Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1/mfg')->group(function (): void {
    Route::get('/boms', [BomController::class, 'index']);
    Route::post('/boms', [BomController::class, 'store']);
    Route::get('/boms/{id}/explode', [BomController::class, 'explode']);
    Route::get('/production-orders', [ProductionOrderController::class, 'index']);
    Route::post('/production-orders', [ProductionOrderController::class, 'store']);
    Route::post('/production-orders/{id}/release', [ProductionOrderController::class, 'release']);
    Route::post('/production-orders/{id}/complete', [ProductionOrderController::class, 'complete']);
    Route::post('/mrp/runs', [MrpController::class, 'run']);
});
