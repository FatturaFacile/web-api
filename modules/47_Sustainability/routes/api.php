<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Sustainability\Http\Controllers\ActivityRecordController;
use Modules\Sustainability\Http\Controllers\EmissionFactorController;
use Modules\Sustainability\Http\Controllers\EsrsController;
use Modules\Sustainability\Http\Controllers\InventoryController;
use Modules\Sustainability\Http\Controllers\KpiController;
use Modules\Sustainability\Http\Controllers\MaterialityController;
use Modules\Sustainability\Http\Controllers\ReductionTargetController;
use Modules\Sustainability\Http\Controllers\ReportingPeriodController;
use Modules\Sustainability\Http\Controllers\Scope3Controller;
use Modules\Sustainability\Http\Controllers\SupplierDataRequestController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| ESG and carbon accounting. module.access:sustainability lets every member read (GET) and only the roles with write
| access of config/permissions.php change anything; the policy keeps the records inside the company and reserves
| reopening a period and approving a materiality assessment to owners, administrators and members granted
| sustainability.approve.
*/
Route::prefix('api/v1/sustainability')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:sustainability'])
    ->name('api.v1.sustainability.')
    ->where(['id' => $uuid])
    ->group(function (): void {
        Route::get('/catalog', [InventoryController::class, 'catalog'])->name('catalog');
        Route::get('/inventory', [InventoryController::class, 'show'])->name('inventory');

        Route::get('/factors', [EmissionFactorController::class, 'index'])->name('factors.index');
        Route::post('/factors', [EmissionFactorController::class, 'store'])->name('factors.store');
        Route::post('/factors/install-examples', [EmissionFactorController::class, 'installExamples'])->name('factors.install-examples');
        Route::get('/factors/{id}', [EmissionFactorController::class, 'show'])->name('factors.show');
        Route::put('/factors/{id}', [EmissionFactorController::class, 'update'])->name('factors.update');
        Route::post('/factors/{id}/new-version', [EmissionFactorController::class, 'newVersion'])->name('factors.new-version');
        Route::delete('/factors/{id}', [EmissionFactorController::class, 'destroy'])->name('factors.destroy');

        Route::get('/periods', [ReportingPeriodController::class, 'index'])->name('periods.index');
        Route::post('/periods', [ReportingPeriodController::class, 'store'])->name('periods.store');
        Route::get('/periods/{id}', [ReportingPeriodController::class, 'show'])->name('periods.show');
        Route::put('/periods/{id}', [ReportingPeriodController::class, 'update'])->name('periods.update');
        Route::post('/periods/{id}/close', [ReportingPeriodController::class, 'close'])->name('periods.close');
        Route::post('/periods/{id}/reopen', [ReportingPeriodController::class, 'reopen'])->name('periods.reopen');

        Route::get('/activity-records', [ActivityRecordController::class, 'index'])->name('activity-records.index');
        Route::post('/activity-records', [ActivityRecordController::class, 'store'])->name('activity-records.store');
        Route::post('/activity-records/bulk', [ActivityRecordController::class, 'bulkStore'])->name('activity-records.bulk');
        Route::get('/activity-records/{id}', [ActivityRecordController::class, 'show'])->name('activity-records.show');
        Route::put('/activity-records/{id}', [ActivityRecordController::class, 'update'])->name('activity-records.update');
        Route::delete('/activity-records/{id}', [ActivityRecordController::class, 'destroy'])->name('activity-records.destroy');

        Route::get('/targets', [ReductionTargetController::class, 'index'])->name('targets.index');
        Route::post('/targets', [ReductionTargetController::class, 'store'])->name('targets.store');
        Route::get('/targets/{id}', [ReductionTargetController::class, 'show'])->name('targets.show');
        Route::put('/targets/{id}', [ReductionTargetController::class, 'update'])->name('targets.update');
        Route::post('/targets/{id}/activate', [ReductionTargetController::class, 'activate'])->name('targets.activate');
        Route::post('/targets/{id}/evaluate', [ReductionTargetController::class, 'evaluate'])->name('targets.evaluate');
        Route::post('/targets/{id}/cancel', [ReductionTargetController::class, 'cancel'])->name('targets.cancel');

        Route::get('/kpis', [KpiController::class, 'index'])->name('kpis.index');
        Route::post('/kpis', [KpiController::class, 'store'])->name('kpis.store');
        Route::post('/kpis/install-defaults', [KpiController::class, 'installDefaults'])->name('kpis.install-defaults');
        Route::put('/kpis/{id}', [KpiController::class, 'update'])->name('kpis.update');
        Route::get('/kpi-values', [KpiController::class, 'values'])->name('kpi-values.index');
        Route::put('/kpi-values', [KpiController::class, 'record'])->name('kpi-values.record');

        Route::get('/materiality-assessments', [MaterialityController::class, 'index'])->name('materiality.index');
        Route::post('/materiality-assessments', [MaterialityController::class, 'store'])->name('materiality.store');
        Route::get('/materiality-assessments/{id}', [MaterialityController::class, 'show'])->name('materiality.show');
        Route::put('/materiality-assessments/{id}', [MaterialityController::class, 'update'])->name('materiality.update');
        Route::post('/materiality-assessments/{id}/approve', [MaterialityController::class, 'approve'])->name('materiality.approve');

        Route::get('/esrs/datapoints', [EsrsController::class, 'datapoints'])->name('esrs.datapoints.index');
        Route::post('/esrs/datapoints', [EsrsController::class, 'storeDatapoint'])->name('esrs.datapoints.store');
        Route::put('/esrs/datapoints/{id}', [EsrsController::class, 'updateDatapoint'])->name('esrs.datapoints.update');
        Route::post('/esrs/install-catalog', [EsrsController::class, 'installCatalog'])->name('esrs.install-catalog');
        Route::get('/periods/{id}/esrs', [EsrsController::class, 'package'])->name('periods.esrs');
        Route::put('/periods/{id}/esrs/values', [EsrsController::class, 'recordValues'])->name('periods.esrs.values');
        Route::post('/periods/{id}/esrs/sync-inventory', [EsrsController::class, 'syncInventory'])->name('periods.esrs.sync-inventory');
        Route::get('/periods/{id}/esrs/completeness', [EsrsController::class, 'completeness'])->name('periods.esrs.completeness');
        Route::get('/periods/{id}/scope3', [Scope3Controller::class, 'show'])->name('periods.scope3');

        Route::get('/supplier-requests', [SupplierDataRequestController::class, 'index'])->name('supplier-requests.index');
        Route::post('/supplier-requests', [SupplierDataRequestController::class, 'store'])->name('supplier-requests.store');
        Route::get('/supplier-requests/{id}', [SupplierDataRequestController::class, 'show'])->name('supplier-requests.show');
        Route::post('/supplier-requests/{id}/receive', [SupplierDataRequestController::class, 'receive'])->name('supplier-requests.receive');
        Route::post('/supplier-requests/{id}/apply', [SupplierDataRequestController::class, 'apply'])->name('supplier-requests.apply');
        Route::post('/supplier-requests/{id}/decline', [SupplierDataRequestController::class, 'decline'])->name('supplier-requests.decline');
        Route::post('/supplier-requests/{id}/cancel', [SupplierDataRequestController::class, 'cancel'])->name('supplier-requests.cancel');
    });
