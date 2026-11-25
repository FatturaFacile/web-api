<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\IndustrialLaundry\Http\Controllers\BillingController;
use Modules\IndustrialLaundry\Http\Controllers\GarmentController;
use Modules\IndustrialLaundry\Http\Controllers\LookupController;
use Modules\IndustrialLaundry\Http\Controllers\MonitorController;
use Modules\IndustrialLaundry\Http\Controllers\ParContractController;
use Modules\IndustrialLaundry\Http\Controllers\RollCageController;
use Modules\IndustrialLaundry\Http\Controllers\ScanController;
use Modules\IndustrialLaundry\Http\Controllers\WashBatchController;

/*
|--------------------------------------------------------------------------
| Industrial laundries and linen / workwear rental (Mod 177 / Issue #423)
|--------------------------------------------------------------------------
| RFID UHF tagged garments, roll cages (returnable containers) weighed at the tunnel gates, customer par stock contracts, the
| PPE wash cycle limit, nominal workwear lockers, UNI EN 14065 RABC certification of the wash batches and the monthly wash +
| rental billing with unreturned garments.
*/
Route::prefix('api/v1/industrial-laundry')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:industrial_laundry'])
    ->group(function (): void {
        Route::get('lookups', [LookupController::class, 'lookups'])->name('industrial-laundry.lookups');

        Route::get('par-stock', [MonitorController::class, 'parStock'])->name('industrial-laundry.par-stock');
        Route::get('dormant-garments', [MonitorController::class, 'dormant'])->name('industrial-laundry.dormant-garments');
        Route::get('ppe-alerts', [MonitorController::class, 'ppeAlerts'])->name('industrial-laundry.ppe-alerts');
        Route::get('lockers', [MonitorController::class, 'lockers'])->name('industrial-laundry.lockers');

        Route::get('garments', [GarmentController::class, 'index'])->name('industrial-laundry.garments.index');
        Route::post('garments', [GarmentController::class, 'store'])->name('industrial-laundry.garments.store');
        Route::get('garments/{id}', [GarmentController::class, 'show'])->whereUuid('id')->name('industrial-laundry.garments.show');
        Route::put('garments/{id}', [GarmentController::class, 'update'])->whereUuid('id')->name('industrial-laundry.garments.update');
        Route::post('garments/{id}/ppe-decision', [MonitorController::class, 'ppeDecision'])->whereUuid('id')->name('industrial-laundry.garments.ppe-decision');
        Route::post('garments/{id}/locker', [MonitorController::class, 'assignLocker'])->whereUuid('id')->name('industrial-laundry.garments.locker');

        Route::get('par-contracts', [ParContractController::class, 'index'])->name('industrial-laundry.par-contracts.index');
        Route::post('par-contracts', [ParContractController::class, 'store'])->name('industrial-laundry.par-contracts.store');
        Route::get('par-contracts/{id}', [ParContractController::class, 'show'])->whereUuid('id')->name('industrial-laundry.par-contracts.show');
        Route::put('par-contracts/{id}', [ParContractController::class, 'update'])->whereUuid('id')->name('industrial-laundry.par-contracts.update');

        Route::get('roll-cages', [RollCageController::class, 'index'])->name('industrial-laundry.roll-cages.index');
        Route::post('roll-cages', [RollCageController::class, 'store'])->name('industrial-laundry.roll-cages.store');
        Route::put('roll-cages/{id}', [RollCageController::class, 'update'])->whereUuid('id')->name('industrial-laundry.roll-cages.update');

        Route::get('scans', [ScanController::class, 'index'])->name('industrial-laundry.scans.index');
        Route::post('scans', [ScanController::class, 'store'])->name('industrial-laundry.scans.store');
        Route::get('scans/{id}', [ScanController::class, 'show'])->whereUuid('id')->name('industrial-laundry.scans.show');

        Route::get('wash-batches', [WashBatchController::class, 'index'])->name('industrial-laundry.wash-batches.index');
        Route::post('wash-batches', [WashBatchController::class, 'store'])->name('industrial-laundry.wash-batches.store');
        Route::get('wash-batches/{id}', [WashBatchController::class, 'show'])->whereUuid('id')->name('industrial-laundry.wash-batches.show');
        Route::post('wash-batches/{id}/certify', [WashBatchController::class, 'certify'])->whereUuid('id')->name('industrial-laundry.wash-batches.certify');

        Route::post('billing-previews', [BillingController::class, 'preview'])->name('industrial-laundry.billing-previews');
        Route::get('billing-runs', [BillingController::class, 'index'])->name('industrial-laundry.billing-runs.index');
        Route::post('billing-runs', [BillingController::class, 'store'])->name('industrial-laundry.billing-runs.store');
        Route::get('billing-runs/{id}', [BillingController::class, 'show'])->whereUuid('id')->name('industrial-laundry.billing-runs.show');
        Route::post('billing-runs/{id}/invoice', [BillingController::class, 'invoice'])->whereUuid('id')->name('industrial-laundry.billing-runs.invoice');
    });
