<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\DairyCheeseManufacturing\Http\Controllers\BreederController;
use Modules\DairyCheeseManufacturing\Http\Controllers\CareController;
use Modules\DairyCheeseManufacturing\Http\Controllers\CurveController;
use Modules\DairyCheeseManufacturing\Http\Controllers\DashboardController;
use Modules\DairyCheeseManufacturing\Http\Controllers\DeliveryController;
use Modules\DairyCheeseManufacturing\Http\Controllers\LookupController;
use Modules\DairyCheeseManufacturing\Http\Controllers\PricingGridController;
use Modules\DairyCheeseManufacturing\Http\Controllers\QualityTestController;
use Modules\DairyCheeseManufacturing\Http\Controllers\RackController;
use Modules\DairyCheeseManufacturing\Http\Controllers\RegulatoryController;
use Modules\DairyCheeseManufacturing\Http\Controllers\SettlementController;
use Modules\DairyCheeseManufacturing\Http\Controllers\TankController;
use Modules\DairyCheeseManufacturing\Http\Controllers\TraceabilityController;
use Modules\DairyCheeseManufacturing\Http\Controllers\VatBatchController;
use Modules\DairyCheeseManufacturing\Http\Controllers\WheelController;

/*
|--------------------------------------------------------------------------
| Dairy, cooperative creamery and alpine pasture vertical (Mod 298 / Issue #544)
|--------------------------------------------------------------------------
| Milk collection and laboratory quality, pricing grids and monthly settlements, vat batches, DOP / AOP aging cellar with dual
| inventory, care scheduler, DBMilch.ch / TSM and SIAN declarations, HACCP bidirectional traceability and recall.
*/
Route::prefix('api/v1/dairy-cheese')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:dairy_cheese'])
    ->group(function (): void {
        Route::get('lookups', [LookupController::class, 'index'])->name('dairy-cheese.lookups');
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dairy-cheese.dashboard');

        Route::get('breeders', [BreederController::class, 'index'])->name('dairy-cheese.breeders.index');
        Route::post('breeders', [BreederController::class, 'store'])->name('dairy-cheese.breeders.store');
        Route::get('breeders/{id}', [BreederController::class, 'show'])->whereUuid('id')->name('dairy-cheese.breeders.show');
        Route::put('breeders/{id}', [BreederController::class, 'update'])->whereUuid('id')->name('dairy-cheese.breeders.update');
        Route::delete('breeders/{id}', [BreederController::class, 'destroy'])->whereUuid('id')->name('dairy-cheese.breeders.destroy');

        Route::get('tanks', [TankController::class, 'index'])->name('dairy-cheese.tanks.index');
        Route::post('tanks', [TankController::class, 'store'])->name('dairy-cheese.tanks.store');
        Route::put('tanks/{id}', [TankController::class, 'update'])->whereUuid('id')->name('dairy-cheese.tanks.update');
        Route::post('tanks/{id}/clean', [TankController::class, 'clean'])->whereUuid('id')->name('dairy-cheese.tanks.clean');

        Route::get('deliveries', [DeliveryController::class, 'index'])->name('dairy-cheese.deliveries.index');
        Route::post('deliveries', [DeliveryController::class, 'store'])->name('dairy-cheese.deliveries.store');
        Route::post('deliveries/bulk', [DeliveryController::class, 'bulk'])->name('dairy-cheese.deliveries.bulk');
        Route::delete('deliveries/{id}', [DeliveryController::class, 'destroy'])->whereUuid('id')->name('dairy-cheese.deliveries.destroy');
        Route::post('deliveries/{id}/release', [DeliveryController::class, 'release'])->whereUuid('id')->name('dairy-cheese.deliveries.release');
        Route::post('deliveries/{id}/reject', [DeliveryController::class, 'reject'])->whereUuid('id')->name('dairy-cheese.deliveries.reject');

        Route::get('quality-tests', [QualityTestController::class, 'index'])->name('dairy-cheese.quality-tests.index');
        Route::post('quality-tests', [QualityTestController::class, 'store'])->name('dairy-cheese.quality-tests.store');
        Route::post('quality-tests/import', [QualityTestController::class, 'import'])->name('dairy-cheese.quality-tests.import');

        Route::get('pricing-grids', [PricingGridController::class, 'index'])->name('dairy-cheese.pricing-grids.index');
        Route::post('pricing-grids', [PricingGridController::class, 'store'])->name('dairy-cheese.pricing-grids.store');
        Route::get('pricing-grids/{id}', [PricingGridController::class, 'show'])->whereUuid('id')->name('dairy-cheese.pricing-grids.show');
        Route::put('pricing-grids/{id}', [PricingGridController::class, 'update'])->whereUuid('id')->name('dairy-cheese.pricing-grids.update');
        Route::delete('pricing-grids/{id}', [PricingGridController::class, 'destroy'])->whereUuid('id')->name('dairy-cheese.pricing-grids.destroy');

        Route::get('settlements', [SettlementController::class, 'index'])->name('dairy-cheese.settlements.index');
        Route::post('settlements/generate', [SettlementController::class, 'generate'])->name('dairy-cheese.settlements.generate');
        Route::get('settlements/{id}', [SettlementController::class, 'show'])->whereUuid('id')->name('dairy-cheese.settlements.show');
        Route::post('settlements/{id}/approve', [SettlementController::class, 'approve'])->whereUuid('id')->name('dairy-cheese.settlements.approve');
        Route::post('settlements/{id}/document', [SettlementController::class, 'document'])->whereUuid('id')->name('dairy-cheese.settlements.document');

        Route::get('vat-batches', [VatBatchController::class, 'index'])->name('dairy-cheese.vat-batches.index');
        Route::post('vat-batches', [VatBatchController::class, 'store'])->name('dairy-cheese.vat-batches.store');
        Route::get('vat-batches/{id}', [VatBatchController::class, 'show'])->whereUuid('id')->name('dairy-cheese.vat-batches.show');
        Route::put('vat-batches/{id}/process', [VatBatchController::class, 'process'])->whereUuid('id')->name('dairy-cheese.vat-batches.process');
        Route::post('vat-batches/{id}/complete', [VatBatchController::class, 'complete'])->whereUuid('id')->name('dairy-cheese.vat-batches.complete');

        Route::get('racks', [RackController::class, 'index'])->name('dairy-cheese.racks.index');
        Route::post('racks', [RackController::class, 'store'])->name('dairy-cheese.racks.store');
        Route::put('racks/{id}', [RackController::class, 'update'])->whereUuid('id')->name('dairy-cheese.racks.update');

        Route::get('wheels', [WheelController::class, 'index'])->name('dairy-cheese.wheels.index');
        Route::post('wheels/sell', [WheelController::class, 'sell'])->name('dairy-cheese.wheels.sell');
        Route::get('wheels/{id}', [WheelController::class, 'show'])->whereUuid('id')->name('dairy-cheese.wheels.show');
        Route::post('wheels/{id}/move', [WheelController::class, 'move'])->whereUuid('id')->name('dairy-cheese.wheels.move');
        Route::post('wheels/{id}/weigh', [WheelController::class, 'weigh'])->whereUuid('id')->name('dairy-cheese.wheels.weigh');
        Route::post('wheels/{id}/grade', [WheelController::class, 'grade'])->whereUuid('id')->name('dairy-cheese.wheels.grade');

        Route::get('weight-loss-curves', [CurveController::class, 'index'])->name('dairy-cheese.curves.index');
        Route::post('weight-loss-curves', [CurveController::class, 'store'])->name('dairy-cheese.curves.store');
        Route::put('weight-loss-curves/{id}', [CurveController::class, 'update'])->whereUuid('id')->name('dairy-cheese.curves.update');

        Route::get('care-logs', [CareController::class, 'index'])->name('dairy-cheese.care-logs.index');
        Route::post('care-logs', [CareController::class, 'store'])->name('dairy-cheese.care-logs.store');
        Route::post('care-logs/execute-batch', [CareController::class, 'executeBatch'])->name('dairy-cheese.care-logs.execute-batch');
        Route::post('care-logs/{id}/execute', [CareController::class, 'execute'])->whereUuid('id')->name('dairy-cheese.care-logs.execute');

        Route::get('regulatory/submissions', [RegulatoryController::class, 'submissions'])->name('dairy-cheese.regulatory.submissions');
        Route::post('regulatory/preview', [RegulatoryController::class, 'preview'])->name('dairy-cheese.regulatory.preview');
        Route::post('regulatory/generate', [RegulatoryController::class, 'generate'])->name('dairy-cheese.regulatory.generate');
        Route::get('regulatory/submissions/{id}/download', [RegulatoryController::class, 'download'])->whereUuid('id')->name('dairy-cheese.regulatory.download');
        Route::post('regulatory/submissions/{id}/mark-submitted', [RegulatoryController::class, 'markSubmitted'])->whereUuid('id')->name('dairy-cheese.regulatory.mark-submitted');

        Route::get('traceability', [TraceabilityController::class, 'trace'])->name('dairy-cheese.traceability');
        Route::post('traceability/recall-report', [TraceabilityController::class, 'recall'])->name('dairy-cheese.traceability.recall');
    });
