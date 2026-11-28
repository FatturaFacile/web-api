<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\OliveMillAndGrainMill\Http\Controllers\ByproductController;
use Modules\OliveMillAndGrainMill\Http\Controllers\DeliveryController;
use Modules\OliveMillAndGrainMill\Http\Controllers\MillingController;
use Modules\OliveMillAndGrainMill\Http\Controllers\OverviewController;
use Modules\OliveMillAndGrainMill\Http\Controllers\SettlementController;
use Modules\OliveMillAndGrainMill\Http\Controllers\SianController;
use Modules\OliveMillAndGrainMill\Http\Controllers\TankController;

/*
|--------------------------------------------------------------------------
| Olive oil mills and grain mills (Mod 297 / Issue #543)
|--------------------------------------------------------------------------
*/
Route::prefix('api/v1/olive-mill')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:olive_mill'])
    ->group(function (): void {
        Route::get('mill-dashboard/stats', [OverviewController::class, 'stats'])->name('olive-mill.dashboard.stats');
        Route::get('reference', [OverviewController::class, 'reference'])->name('olive-mill.reference');

        Route::get('olive-deliveries', [DeliveryController::class, 'index'])->name('olive-mill.deliveries.index');
        Route::post('olive-deliveries', [DeliveryController::class, 'store'])->name('olive-mill.deliveries.store');
        Route::get('olive-deliveries/{id}', [DeliveryController::class, 'show'])->whereUuid('id')->name('olive-mill.deliveries.show');
        Route::put('olive-deliveries/{id}', [DeliveryController::class, 'update'])->whereUuid('id')->name('olive-mill.deliveries.update');
        Route::delete('olive-deliveries/{id}', [DeliveryController::class, 'destroy'])->whereUuid('id')->name('olive-mill.deliveries.destroy');
        Route::post('olive-deliveries/{id}/weigh', [DeliveryController::class, 'weigh'])->whereUuid('id')->name('olive-mill.deliveries.weigh');
        Route::post('olive-deliveries/{id}/send-sms-reminder', [DeliveryController::class, 'sendSmsReminder'])->whereUuid('id')->name('olive-mill.deliveries.sms');

        Route::get('milling-schedule', [MillingController::class, 'schedule'])->name('olive-mill.schedule');
        Route::get('milling-cycles', [MillingController::class, 'index'])->name('olive-mill.cycles.index');
        Route::post('milling-cycles', [MillingController::class, 'store'])->name('olive-mill.cycles.store');
        Route::get('milling-cycles/{id}', [MillingController::class, 'show'])->whereUuid('id')->name('olive-mill.cycles.show');
        Route::post('milling-cycles/{id}/complete', [MillingController::class, 'complete'])->whereUuid('id')->name('olive-mill.cycles.complete');

        Route::post('milling-cycles/{id}/settle', [SettlementController::class, 'settle'])->whereUuid('id')->name('olive-mill.settlements.settle');
        Route::get('settlements', [SettlementController::class, 'index'])->name('olive-mill.settlements.index');

        Route::post('sian-tanks/transfer', [TankController::class, 'transfer'])->name('olive-mill.tanks.transfer');
        Route::post('sian-tanks/package', [TankController::class, 'package'])->name('olive-mill.tanks.package');
        Route::post('sian-tanks/adjust', [TankController::class, 'adjust'])->name('olive-mill.tanks.adjust');
        Route::get('sian-tanks', [TankController::class, 'index'])->name('olive-mill.tanks.index');
        Route::post('sian-tanks', [TankController::class, 'store'])->name('olive-mill.tanks.store');
        Route::get('sian-tanks/{id}', [TankController::class, 'show'])->whereUuid('id')->name('olive-mill.tanks.show');
        Route::put('sian-tanks/{id}', [TankController::class, 'update'])->whereUuid('id')->name('olive-mill.tanks.update');
        Route::delete('sian-tanks/{id}', [TankController::class, 'destroy'])->whereUuid('id')->name('olive-mill.tanks.destroy');

        Route::get('sian-movements', [SianController::class, 'index'])->name('olive-mill.sian.index');
        Route::get('sian-movements/compliance-alerts', [SianController::class, 'alerts'])->name('olive-mill.sian.alerts');
        Route::post('sian-movements/transmit', [SianController::class, 'transmit'])->name('olive-mill.sian.transmit');
        Route::post('sian-movements/export/{format}', [SianController::class, 'export'])->whereIn('format', ['xml', 'csv'])->name('olive-mill.sian.export');

        Route::get('mill-byproducts', [ByproductController::class, 'index'])->name('olive-mill.byproducts.index');
        Route::post('mill-byproducts', [ByproductController::class, 'store'])->name('olive-mill.byproducts.store');
    });

// A simulation saves nothing: it needs read access only, even though it is a POST.
Route::prefix('api/v1/olive-mill')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:olive_mill,read'])
    ->group(function (): void {
        Route::post('milling-cycles/{id}/preview-settlement', [SettlementController::class, 'preview'])->whereUuid('id')->name('olive-mill.settlements.preview');
    });
