<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\AssetManagement\Http\Controllers\AssetCatalogController;
use Modules\AssetManagement\Http\Controllers\DepreciationController;
use Modules\AssetManagement\Http\Controllers\FixedAssetController;
use Modules\AssetManagement\Http\Controllers\MaintenanceController;

Route::prefix('api/v1/eam')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        // Catalogs & Master Data
        Route::get('/categories', [AssetCatalogController::class, 'categories'])->name('eam.categories.index');
        Route::post('/categories', [AssetCatalogController::class, 'storeCategory'])->name('eam.categories.store');
        Route::get('/classes', [AssetCatalogController::class, 'classes'])->name('eam.classes.index');
        Route::get('/locations', [AssetCatalogController::class, 'locations'])->name('eam.locations.index');
        Route::post('/locations', [AssetCatalogController::class, 'storeLocation'])->name('eam.locations.store');

        // Fixed Assets Lifecycle
        Route::get('/assets', [FixedAssetController::class, 'index'])->name('eam.assets.index');
        Route::post('/assets', [FixedAssetController::class, 'store'])->name('eam.assets.store');
        Route::get('/assets/{id}', [FixedAssetController::class, 'show'])->name('eam.assets.show');
        Route::put('/assets/{id}', [FixedAssetController::class, 'update'])->name('eam.assets.update');
        Route::patch('/assets/{id}', [FixedAssetController::class, 'update'])->name('eam.assets.patch');
        Route::delete('/assets/{id}', [FixedAssetController::class, 'destroy'])->name('eam.assets.destroy');

        Route::post('/assets/{id}/capitalize', [FixedAssetController::class, 'capitalize'])->name('eam.assets.capitalize');
        Route::post('/assets/{id}/commission', [FixedAssetController::class, 'commission'])->name('eam.assets.commission');
        Route::post('/assets/{id}/transfer', [FixedAssetController::class, 'transfer'])->name('eam.assets.transfer');
        Route::post('/assets/{id}/valuate', [FixedAssetController::class, 'valuate'])->name('eam.assets.valuate');
        Route::post('/assets/{id}/reverse-impairment', [FixedAssetController::class, 'reverseImpairment'])->name('eam.assets.reverse_impairment');
        Route::post('/assets/{id}/dispose', [FixedAssetController::class, 'dispose'])->name('eam.assets.dispose');
        Route::post('/assets/{id}/components', [FixedAssetController::class, 'attachComponent'])->name('eam.assets.components.store');
        Route::post('/assets/{id}/custodians', [FixedAssetController::class, 'assignCustodian'])->name('eam.assets.custodians.store');
        Route::post('/assets/{id}/assignments', [FixedAssetController::class, 'assignAsset'])->name('eam.assets.assignments.store');
        Route::post('/assets/{id}/project-allocations', [FixedAssetController::class, 'allocateToProject'])->name('eam.assets.project_allocations.store');
        Route::get('/assets/{id}/depreciation-schedules', [DepreciationController::class, 'assetSchedules'])->name('eam.assets.depreciation_schedules');

        // Depreciation Engine Runs
        Route::get('/depreciation/runs', [DepreciationController::class, 'indexRuns'])->name('eam.depreciation.runs.index');
        Route::post('/depreciation/runs', [DepreciationController::class, 'executeRun'])->name('eam.depreciation.runs.execute');
        Route::get('/depreciation/runs/{id}', [DepreciationController::class, 'showRun'])->name('eam.depreciation.runs.show');

        // Maintenance Plans & Work Orders
        Route::get('/maintenance/plans', [MaintenanceController::class, 'indexPlans'])->name('eam.maintenance.plans.index');
        Route::post('/maintenance/plans', [MaintenanceController::class, 'storePlan'])->name('eam.maintenance.plans.store');
        Route::get('/maintenance/work-orders', [MaintenanceController::class, 'indexWorkOrders'])->name('eam.maintenance.work_orders.index');
        Route::post('/maintenance/work-orders', [MaintenanceController::class, 'storeWorkOrder'])->name('eam.maintenance.work_orders.store');
        Route::get('/maintenance/work-orders/{id}', [MaintenanceController::class, 'showWorkOrder'])->name('eam.maintenance.work_orders.show');
        Route::post('/maintenance/work-orders/{id}/complete', [MaintenanceController::class, 'completeWorkOrder'])->name('eam.maintenance.work_orders.complete');
    });
