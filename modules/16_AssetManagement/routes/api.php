<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\AssetManagement\Http\Controllers\AssetCatalogController;
use Modules\AssetManagement\Http\Controllers\AssetOperationsController;
use Modules\AssetManagement\Http\Controllers\AssetReportController;
use Modules\AssetManagement\Http\Controllers\DepreciationController;
use Modules\AssetManagement\Http\Controllers\FixedAssetController;
use Modules\AssetManagement\Http\Controllers\MaintenanceController;

/*
| Asset management (EAM). module.access:assets lets every member read (GET) and only the roles with write access of
| config/permissions.php change anything.
*/
Route::prefix('api/v1/eam')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:assets'])
    ->group(function (): void {
        // Catalogs & master data
        Route::get('/categories', [AssetCatalogController::class, 'categories'])->name('eam.categories.index');
        Route::post('/categories', [AssetCatalogController::class, 'storeCategory'])->name('eam.categories.store');
        Route::get('/categories/{id}', [AssetCatalogController::class, 'showCategory'])->whereUuid('id')->name('eam.categories.show');
        Route::put('/categories/{id}', [AssetCatalogController::class, 'updateCategory'])->whereUuid('id')->name('eam.categories.update');
        Route::get('/classes', [AssetCatalogController::class, 'classes'])->name('eam.classes.index');
        Route::post('/classes', [AssetCatalogController::class, 'storeClass'])->name('eam.classes.store');
        Route::get('/locations', [AssetCatalogController::class, 'locations'])->name('eam.locations.index');
        Route::post('/locations', [AssetCatalogController::class, 'storeLocation'])->name('eam.locations.store');
        Route::put('/locations/{id}', [AssetCatalogController::class, 'updateLocation'])->whereUuid('id')->name('eam.locations.update');

        // Fixed assets lifecycle
        Route::get('/assets', [FixedAssetController::class, 'index'])->name('eam.assets.index');
        Route::post('/assets', [FixedAssetController::class, 'store'])->name('eam.assets.store');
        Route::post('/assets/acquire', [FixedAssetController::class, 'acquire'])->name('eam.assets.acquire');
        Route::get('/assets/tags', [AssetOperationsController::class, 'tags'])->name('eam.assets.tags');

        Route::prefix('/assets/{id}')->whereUuid('id')->group(function (): void {
            Route::get('/', [FixedAssetController::class, 'show'])->name('eam.assets.show');
            Route::put('/', [FixedAssetController::class, 'update'])->name('eam.assets.update');
            Route::patch('/', [FixedAssetController::class, 'update'])->name('eam.assets.patch');
            Route::delete('/', [FixedAssetController::class, 'destroy'])->name('eam.assets.destroy');
            Route::post('/capitalize', [FixedAssetController::class, 'capitalize'])->name('eam.assets.capitalize');
            Route::post('/commission', [FixedAssetController::class, 'commission'])->name('eam.assets.commission');
            Route::post('/transfer', [FixedAssetController::class, 'transfer'])->name('eam.assets.transfer');
            Route::post('/valuate', [FixedAssetController::class, 'valuate'])->name('eam.assets.valuate');
            Route::post('/reverse-impairment', [FixedAssetController::class, 'reverseImpairment'])->name('eam.assets.reverse_impairment');
            Route::post('/dispose', [FixedAssetController::class, 'dispose'])->name('eam.assets.dispose');
            Route::get('/history', [FixedAssetController::class, 'history'])->name('eam.assets.history');

            Route::get('/components', [AssetOperationsController::class, 'components'])->name('eam.assets.components.index');
            Route::post('/components', [AssetOperationsController::class, 'attachComponent'])->name('eam.assets.components.store');
            Route::post('/components/{componentId}/retire', [AssetOperationsController::class, 'retireComponent'])->whereUuid('componentId')->name('eam.assets.components.retire');
            Route::post('/custodians', [AssetOperationsController::class, 'assignCustodian'])->name('eam.assets.custodians.store');
            Route::post('/assignments', [AssetOperationsController::class, 'assignAsset'])->name('eam.assets.assignments.store');
            Route::post('/project-allocations', [AssetOperationsController::class, 'allocateToProject'])->name('eam.assets.project_allocations.store');
            Route::get('/books', [AssetOperationsController::class, 'books'])->name('eam.assets.books');
            Route::get('/tag', [AssetOperationsController::class, 'tag'])->name('eam.assets.tag');
            Route::get('/meter-readings', [AssetOperationsController::class, 'meterReadings'])->name('eam.assets.meter_readings.index');
            Route::post('/meter-readings', [AssetOperationsController::class, 'storeMeterReading'])->name('eam.assets.meter_readings.store');
            Route::get('/maintenance-summary', [AssetOperationsController::class, 'maintenanceSummary'])->name('eam.assets.maintenance_summary');

            Route::get('/depreciation-schedules', [DepreciationController::class, 'assetSchedules'])->name('eam.assets.depreciation_schedules');
            Route::get('/depreciation-preview', [DepreciationController::class, 'preview'])->name('eam.assets.depreciation_preview');
        });

        // Depreciation engine (runs are manual by design)
        Route::get('/depreciation/runs', [DepreciationController::class, 'indexRuns'])->name('eam.depreciation.runs.index');
        Route::post('/depreciation/runs', [DepreciationController::class, 'executeRun'])->name('eam.depreciation.runs.execute');
        Route::get('/depreciation/runs/{id}', [DepreciationController::class, 'showRun'])->whereUuid('id')->name('eam.depreciation.runs.show');
        Route::post('/depreciation/simulate', [DepreciationController::class, 'simulate'])->name('eam.depreciation.simulate');

        // Reports & alerts
        Route::get('/reports/asset-register', [AssetReportController::class, 'register'])->name('eam.reports.asset_register');
        Route::get('/alerts/expiring', [AssetReportController::class, 'expiring'])->name('eam.alerts.expiring');

        // Maintenance plans & work orders
        Route::get('/maintenance/plans', [MaintenanceController::class, 'indexPlans'])->name('eam.maintenance.plans.index');
        Route::post('/maintenance/plans', [MaintenanceController::class, 'storePlan'])->name('eam.maintenance.plans.store');
        Route::get('/maintenance/plans/due', [MaintenanceController::class, 'duePlans'])->name('eam.maintenance.plans.due');
        Route::get('/maintenance/plans/{id}', [MaintenanceController::class, 'showPlan'])->whereUuid('id')->name('eam.maintenance.plans.show');
        Route::put('/maintenance/plans/{id}', [MaintenanceController::class, 'updatePlan'])->whereUuid('id')->name('eam.maintenance.plans.update');
        Route::post('/maintenance/plans/{id}/work-orders', [MaintenanceController::class, 'generateWorkOrder'])->whereUuid('id')->name('eam.maintenance.plans.work_orders.store');
        Route::get('/maintenance/work-orders', [MaintenanceController::class, 'indexWorkOrders'])->name('eam.maintenance.work_orders.index');
        Route::post('/maintenance/work-orders', [MaintenanceController::class, 'storeWorkOrder'])->name('eam.maintenance.work_orders.store');
        Route::get('/maintenance/work-orders/{id}', [MaintenanceController::class, 'showWorkOrder'])->whereUuid('id')->name('eam.maintenance.work_orders.show');
        Route::post('/maintenance/work-orders/{id}/start', [MaintenanceController::class, 'startWorkOrder'])->whereUuid('id')->name('eam.maintenance.work_orders.start');
        Route::post('/maintenance/work-orders/{id}/parts', [MaintenanceController::class, 'consumePart'])->whereUuid('id')->name('eam.maintenance.work_orders.parts.store');
        Route::post('/maintenance/work-orders/{id}/complete', [MaintenanceController::class, 'completeWorkOrder'])->whereUuid('id')->name('eam.maintenance.work_orders.complete');
        Route::post('/maintenance/work-orders/{id}/cancel', [MaintenanceController::class, 'cancelWorkOrder'])->whereUuid('id')->name('eam.maintenance.work_orders.cancel');
    });
