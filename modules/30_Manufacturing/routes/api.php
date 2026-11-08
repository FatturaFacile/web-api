<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Manufacturing\Http\Controllers\BomController;
use Modules\Manufacturing\Http\Controllers\EngineeringChangeController;
use Modules\Manufacturing\Http\Controllers\ItemController;
use Modules\Manufacturing\Http\Controllers\ManufacturingKpiController;
use Modules\Manufacturing\Http\Controllers\ManufacturingSettingsController;
use Modules\Manufacturing\Http\Controllers\MrpController;
use Modules\Manufacturing\Http\Controllers\ProductionExecutionController;
use Modules\Manufacturing\Http\Controllers\ProductionOrderController;
use Modules\Manufacturing\Http\Controllers\QualityController;
use Modules\Manufacturing\Http\Controllers\RoutingController;
use Modules\Manufacturing\Http\Controllers\ShopFloorController;
use Modules\Manufacturing\Http\Controllers\SubcontractController;
use Modules\Manufacturing\Http\Controllers\WorkCenterController;

/*
| Manufacturing. module.access:manufacturing lets every member read (GET) and only the roles with write access of
| config/permissions.php change anything; the policies add the record's company and, for sensitive actions (BOM
| release, engineering change decisions, order close, ledger accounts), the manager roles.
*/
Route::prefix('api/v1/manufacturing')->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:manufacturing'])->group(function (): void {
    // Items
    Route::get('/items', [ItemController::class, 'index'])->name('mfg.items.index');
    Route::post('/items', [ItemController::class, 'store'])->name('mfg.items.store');
    Route::get('/items/{id}', [ItemController::class, 'show'])->whereUuid('id')->name('mfg.items.show');
    Route::put('/items/{id}', [ItemController::class, 'update'])->whereUuid('id')->name('mfg.items.update');
    Route::post('/items/{id}/cost-rollup', [ItemController::class, 'costRollup'])->whereUuid('id')->name('mfg.items.cost_rollup');
    Route::get('/items/{id}/where-used', [ItemController::class, 'whereUsed'])->whereUuid('id')->name('mfg.items.where_used');

    // Work centres
    Route::get('/work-centers', [WorkCenterController::class, 'index'])->name('mfg.work_centers.index');
    Route::post('/work-centers', [WorkCenterController::class, 'store'])->name('mfg.work_centers.store');
    Route::get('/work-centers/{id}', [WorkCenterController::class, 'show'])->whereUuid('id')->name('mfg.work_centers.show');
    Route::put('/work-centers/{id}', [WorkCenterController::class, 'update'])->whereUuid('id')->name('mfg.work_centers.update');
    Route::put('/work-centers/{id}/calendar', [WorkCenterController::class, 'setCalendar'])->whereUuid('id')->name('mfg.work_centers.calendar');
    Route::post('/work-centers/{id}/calendar-exceptions', [WorkCenterController::class, 'storeException'])->whereUuid('id')->name('mfg.work_centers.exceptions.store');
    Route::get('/work-centers/{id}/capacity', [WorkCenterController::class, 'capacity'])->whereUuid('id')->name('mfg.work_centers.capacity');

    // BOMs
    Route::get('/boms', [BomController::class, 'index'])->name('mfg.boms.index');
    Route::post('/boms', [BomController::class, 'store'])->name('mfg.boms.store');
    Route::get('/boms/{id}', [BomController::class, 'show'])->whereUuid('id')->name('mfg.boms.show');
    Route::put('/boms/{id}', [BomController::class, 'update'])->whereUuid('id')->name('mfg.boms.update');
    Route::get('/boms/{id}/explode', [BomController::class, 'explode'])->whereUuid('id')->name('mfg.boms.explode');
    Route::post('/boms/{id}/revise', [BomController::class, 'revise'])->whereUuid('id')->name('mfg.boms.revise');
    Route::post('/boms/{id}/activate', [BomController::class, 'activate'])->whereUuid('id')->name('mfg.boms.activate');

    // Routings
    Route::get('/routings', [RoutingController::class, 'index'])->name('mfg.routings.index');
    Route::post('/routings', [RoutingController::class, 'store'])->name('mfg.routings.store');
    Route::get('/routings/{id}', [RoutingController::class, 'show'])->whereUuid('id')->name('mfg.routings.show');

    // Engineering changes
    Route::get('/engineering-changes', [EngineeringChangeController::class, 'index'])->name('mfg.ecos.index');
    Route::post('/engineering-changes', [EngineeringChangeController::class, 'store'])->name('mfg.ecos.store');
    Route::get('/engineering-changes/{id}', [EngineeringChangeController::class, 'show'])->whereUuid('id')->name('mfg.ecos.show');
    Route::post('/engineering-changes/{id}/submit', [EngineeringChangeController::class, 'submit'])->whereUuid('id')->name('mfg.ecos.submit');
    Route::post('/engineering-changes/{id}/approve', [EngineeringChangeController::class, 'approve'])->whereUuid('id')->name('mfg.ecos.approve');
    Route::post('/engineering-changes/{id}/reject', [EngineeringChangeController::class, 'reject'])->whereUuid('id')->name('mfg.ecos.reject');
    Route::post('/engineering-changes/{id}/implement', [EngineeringChangeController::class, 'implement'])->whereUuid('id')->name('mfg.ecos.implement');

    // Production orders
    Route::get('/production-orders', [ProductionOrderController::class, 'index'])->name('mfg.production_orders.index');
    Route::post('/production-orders', [ProductionOrderController::class, 'store'])->name('mfg.production_orders.store');
    Route::prefix('/production-orders/{id}')->whereUuid('id')->group(function (): void {
        Route::get('/', [ProductionOrderController::class, 'show'])->name('mfg.production_orders.show');
        Route::post('/reschedule', [ProductionOrderController::class, 'reschedule'])->name('mfg.production_orders.reschedule');
        Route::post('/release', [ProductionOrderController::class, 'release'])->name('mfg.production_orders.release');
        Route::post('/start', [ProductionOrderController::class, 'start'])->name('mfg.production_orders.start');
        Route::post('/complete', [ProductionOrderController::class, 'complete'])->name('mfg.production_orders.complete');
        Route::post('/close', [ProductionOrderController::class, 'close'])->name('mfg.production_orders.close');
        Route::post('/cancel', [ProductionOrderController::class, 'cancel'])->name('mfg.production_orders.cancel');
        Route::post('/issues', [ProductionExecutionController::class, 'issue'])->name('mfg.production_orders.issues');
        Route::post('/operations/{operationId}/confirmations', [ProductionExecutionController::class, 'confirm'])->whereUuid('operationId')->name('mfg.production_orders.confirmations');
        Route::post('/receipts', [ProductionExecutionController::class, 'receive'])->name('mfg.production_orders.receipts');
        Route::get('/transactions', [ProductionExecutionController::class, 'transactions'])->name('mfg.production_orders.transactions');
        Route::get('/cost-rollup', [ProductionExecutionController::class, 'costs'])->name('mfg.production_orders.cost_rollup');
        Route::post('/subcontract-orders', [SubcontractController::class, 'send'])->name('mfg.production_orders.subcontract');
    });

    // Subcontracting
    Route::get('/subcontract-orders', [SubcontractController::class, 'index'])->name('mfg.subcontract_orders.index');
    Route::get('/subcontract-orders/{id}', [SubcontractController::class, 'show'])->whereUuid('id')->name('mfg.subcontract_orders.show');
    Route::post('/subcontract-orders/{id}/receive', [SubcontractController::class, 'receive'])->whereUuid('id')->name('mfg.subcontract_orders.receive');
    Route::post('/subcontract-orders/{id}/cancel', [SubcontractController::class, 'cancel'])->whereUuid('id')->name('mfg.subcontract_orders.cancel');

    // MRP
    Route::get('/mrp/runs', [MrpController::class, 'index'])->name('mfg.mrp.runs.index');
    Route::post('/mrp/runs', [MrpController::class, 'run'])->name('mfg.mrp.runs.store');
    Route::get('/mrp/runs/{id}', [MrpController::class, 'show'])->whereUuid('id')->name('mfg.mrp.runs.show');
    Route::get('/mrp/planned-orders', [MrpController::class, 'plannedOrders'])->name('mfg.mrp.planned_orders');
    Route::get('/mrp/purchase-suggestions', [MrpController::class, 'purchaseSuggestions'])->name('mfg.mrp.purchase_suggestions');
    Route::get('/mrp/shortages', [MrpController::class, 'shortages'])->name('mfg.mrp.shortages');
    Route::post('/mrp/planned-orders/{id}/convert', [MrpController::class, 'convertPlannedOrder'])->whereUuid('id')->name('mfg.mrp.planned_orders.convert');
    Route::post('/mrp/planned-orders/{id}/procurement-link', [MrpController::class, 'linkProcurement'])->whereUuid('id')->name('mfg.mrp.planned_orders.procurement_link');
    Route::get('/mrp/forecasts', [MrpController::class, 'forecasts'])->name('mfg.mrp.forecasts.index');
    Route::post('/mrp/forecasts', [MrpController::class, 'storeForecast'])->name('mfg.mrp.forecasts.store');
    Route::delete('/mrp/forecasts/{id}', [MrpController::class, 'destroyForecast'])->whereUuid('id')->name('mfg.mrp.forecasts.destroy');

    // Shop floor
    Route::get('/shop-floor/executions', [ShopFloorController::class, 'index'])->name('mfg.shop_floor.executions');
    Route::post('/shop-floor/executions', [ShopFloorController::class, 'recordExecution'])->name('mfg.shop_floor.record_execution');
    Route::post('/shop-floor/executions/{id}/complete', [ShopFloorController::class, 'completeExecution'])->whereUuid('id')->name('mfg.shop_floor.complete_execution');
    Route::post('/shop-floor/executions/{id}/downtime', [ShopFloorController::class, 'recordDowntime'])->whereUuid('id')->name('mfg.shop_floor.record_downtime');

    // Quality
    Route::get('/quality/plans', [QualityController::class, 'plans'])->name('mfg.quality.plans');
    Route::post('/quality/plans', [QualityController::class, 'storePlan'])->name('mfg.quality.plans.store');
    Route::get('/quality/inspections', [QualityController::class, 'inspections'])->name('mfg.quality.inspections');
    Route::post('/quality/inspections', [QualityController::class, 'recordInspection'])->name('mfg.quality.record_inspection');
    Route::get('/quality/non-conformances', [QualityController::class, 'nonConformances'])->name('mfg.quality.non_conformances');
    Route::post('/quality/non-conformances', [QualityController::class, 'storeNonConformance'])->name('mfg.quality.non_conformances.store');
    Route::post('/quality/non-conformances/{id}/resolve', [QualityController::class, 'resolveNonConformance'])->whereUuid('id')->name('mfg.quality.non_conformances.resolve');

    // Settings and KPIs
    Route::get('/settings/accounting', [ManufacturingSettingsController::class, 'show'])->name('mfg.settings.accounting');
    Route::put('/settings/accounting', [ManufacturingSettingsController::class, 'update'])->name('mfg.settings.accounting.update');
    Route::get('/kpis', ManufacturingKpiController::class)->name('mfg.kpis');
});
