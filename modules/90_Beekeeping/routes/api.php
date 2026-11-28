<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Beekeeping\Http\Controllers\ApiaryController;
use Modules\Beekeeping\Http\Controllers\CensusController;
use Modules\Beekeeping\Http\Controllers\DashboardController;
use Modules\Beekeeping\Http\Controllers\DocumentController;
use Modules\Beekeeping\Http\Controllers\HiveController;
use Modules\Beekeeping\Http\Controllers\HoneyController;
use Modules\Beekeeping\Http\Controllers\InspectionController;
use Modules\Beekeeping\Http\Controllers\LookupController;
use Modules\Beekeeping\Http\Controllers\MovementController;
use Modules\Beekeeping\Http\Controllers\TaxController;
use Modules\Beekeeping\Http\Controllers\TraceabilityController;
use Modules\Beekeeping\Http\Controllers\VarroaController;

/*
|--------------------------------------------------------------------------
| Beekeeping and honey production (Mod 296 / Issue #542)
|--------------------------------------------------------------------------
| Apiaries and hives with queen tracking, inspections, BDA / cantonal census and nomadism, the veterinary varroa register with the
| withdrawal lock, the honey room with HACCP traceability and the agricultural VAT.
*/
Route::prefix('api/v1/beekeeping')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:beekeeping'])
    ->group(function (): void {
        Route::get('lookups', [LookupController::class, 'index'])->name('beekeeping.lookups');
        Route::get('dashboard', [DashboardController::class, 'index'])->name('beekeeping.dashboard');
        Route::get('map', [DashboardController::class, 'map'])->name('beekeeping.map');

        Route::get('apiaries', [ApiaryController::class, 'index'])->name('beekeeping.apiaries.index');
        Route::post('apiaries', [ApiaryController::class, 'store'])->name('beekeeping.apiaries.store');
        Route::get('apiaries/{id}', [ApiaryController::class, 'show'])->whereUuid('id')->name('beekeeping.apiaries.show');
        Route::put('apiaries/{id}', [ApiaryController::class, 'update'])->whereUuid('id')->name('beekeeping.apiaries.update');
        Route::delete('apiaries/{id}', [ApiaryController::class, 'destroy'])->whereUuid('id')->name('beekeeping.apiaries.destroy');
        Route::get('apiaries/{id}/locks', [VarroaController::class, 'locks'])->whereUuid('id')->name('beekeeping.apiaries.locks');

        Route::get('hives', [HiveController::class, 'index'])->name('beekeeping.hives.index');
        Route::post('hives', [HiveController::class, 'store'])->name('beekeeping.hives.store');
        Route::get('hives/by-qr/{token}', [HiveController::class, 'byQr'])->name('beekeeping.hives.by-qr');
        Route::get('hives/{id}', [HiveController::class, 'show'])->whereUuid('id')->name('beekeeping.hives.show');
        Route::put('hives/{id}', [HiveController::class, 'update'])->whereUuid('id')->name('beekeeping.hives.update');
        Route::delete('hives/{id}', [HiveController::class, 'destroy'])->whereUuid('id')->name('beekeeping.hives.destroy');
        Route::get('hives/{id}/inspections', [InspectionController::class, 'index'])->whereUuid('id')->name('beekeeping.inspections.index');
        Route::post('hives/{id}/inspections', [InspectionController::class, 'store'])->whereUuid('id')->name('beekeeping.inspections.store');
        Route::post('inspections/batch', [InspectionController::class, 'batch'])->name('beekeeping.inspections.batch');

        Route::get('varroa/treatments', [VarroaController::class, 'index'])->name('beekeeping.varroa.treatments.index');
        Route::post('varroa/treatments', [VarroaController::class, 'store'])->name('beekeeping.varroa.treatments.store');
        Route::get('varroa/counts', [VarroaController::class, 'counts'])->name('beekeeping.varroa.counts.index');
        Route::post('varroa/counts', [VarroaController::class, 'storeCount'])->name('beekeeping.varroa.counts.store');

        Route::get('movements', [MovementController::class, 'index'])->name('beekeeping.movements.index');
        Route::post('movements', [MovementController::class, 'store'])->name('beekeeping.movements.store');
        Route::get('movements/{id}', [MovementController::class, 'show'])->whereUuid('id')->name('beekeeping.movements.show');
        Route::post('movements/{id}/status', [MovementController::class, 'status'])->whereUuid('id')->name('beekeeping.movements.status');

        Route::get('census', [CensusController::class, 'index'])->name('beekeeping.census.index');
        Route::post('census/generate', [CensusController::class, 'generate'])->name('beekeeping.census.generate');
        Route::post('census/{id}/submit', [CensusController::class, 'submit'])->whereUuid('id')->name('beekeeping.census.submit');
        Route::get('census/{id}/export/csv', [CensusController::class, 'csv'])->whereUuid('id')->name('beekeeping.census.csv');
        Route::get('census/{id}/export/xml', [CensusController::class, 'xml'])->whereUuid('id')->name('beekeeping.census.xml');

        Route::get('intakes', [HoneyController::class, 'intakes'])->name('beekeeping.intakes.index');
        Route::post('intakes', [HoneyController::class, 'storeIntake'])->name('beekeeping.intakes.store');
        Route::get('batches', [HoneyController::class, 'batches'])->name('beekeeping.batches.index');
        Route::post('batches', [HoneyController::class, 'storeBatch'])->name('beekeeping.batches.store');
        Route::get('batches/{id}', [HoneyController::class, 'showBatch'])->whereUuid('id')->name('beekeeping.batches.show');
        Route::put('batches/{id}/analysis', [HoneyController::class, 'analysis'])->whereUuid('id')->name('beekeeping.batches.analysis');
        Route::post('batches/{id}/jarring', [HoneyController::class, 'jar'])->whereUuid('id')->name('beekeeping.batches.jarring');

        Route::get('traceability/backward/{lotCode}', [TraceabilityController::class, 'backward'])->name('beekeeping.traceability.backward');
        Route::get('traceability/forward', [TraceabilityController::class, 'forward'])->name('beekeeping.traceability.forward');

        Route::get('tax/profile', [TaxController::class, 'profile'])->name('beekeeping.tax.profile');
        Route::put('tax/profile', [TaxController::class, 'saveProfile'])->name('beekeeping.tax.profile.save');

        Route::get('documents/hive-qr-sheet', [DocumentController::class, 'qrSheet'])->name('beekeeping.documents.qr-sheet');
        Route::get('documents/jarring-runs/{id}/label', [DocumentController::class, 'jarLabel'])->whereUuid('id')->name('beekeeping.documents.jar-label');
        Route::get('documents/varroa-register', [DocumentController::class, 'varroaRegister'])->name('beekeeping.documents.varroa-register');
        Route::get('documents/movements/{id}', [DocumentController::class, 'movement'])->whereUuid('id')->name('beekeeping.documents.movement');
        Route::get('documents/census/{id}', [DocumentController::class, 'census'])->whereUuid('id')->name('beekeeping.documents.census');
        Route::get('documents/batches/{id}/haccp', [DocumentController::class, 'haccp'])->whereUuid('id')->name('beekeeping.documents.haccp');
    });

// A quote saves nothing: it needs read access only, even though it is a POST.
Route::prefix('api/v1/beekeeping')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:beekeeping,read'])
    ->group(function (): void {
        Route::post('tax/quote', [TaxController::class, 'quote'])->name('beekeeping.tax.quote');
    });
