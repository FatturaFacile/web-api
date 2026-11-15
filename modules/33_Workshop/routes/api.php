<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Workshop\Http\Controllers\CustomerVehicleController;
use Modules\Workshop\Http\Controllers\EstimateController;
use Modules\Workshop\Http\Controllers\FolderController;
use Modules\Workshop\Http\Controllers\LabourTimeController;
use Modules\Workshop\Http\Controllers\PartConsumptionController;
use Modules\Workshop\Http\Controllers\RecurringServiceController;
use Modules\Workshop\Http\Controllers\SchedulingController;
use Modules\Workshop\Http\Controllers\VehicleStockController;
use Modules\Workshop\Http\Controllers\WarrantyClaimController;

Route::prefix('api/v1/workshop')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:workshop'])
    ->name('api.v1.workshop.')
    ->where(['id' => '[0-9a-fA-F-]{36}', 'lineId' => '[0-9a-fA-F-]{36}'])
    ->group(function (): void {
        Route::get('/folders', [FolderController::class, 'index'])->name('folders.index');
        Route::post('/folders', [FolderController::class, 'store'])->name('folders.store');
        Route::get('/folders/{id}', [FolderController::class, 'show'])->name('folders.show');
        Route::put('/folders/{id}', [FolderController::class, 'update'])->name('folders.update');
        Route::post('/folders/{id}/lines', [FolderController::class, 'storeLine'])->name('folders.lines.store');
        Route::delete('/folders/{id}/lines/{lineId}', [FolderController::class, 'destroyLine'])->name('folders.lines.destroy');
        Route::post('/folders/{id}/status', [FolderController::class, 'status'])->name('folders.status');
        Route::post('/folders/{id}/quote', [FolderController::class, 'quote'])->name('folders.quote');
        Route::post('/folders/{id}/delivery-note', [FolderController::class, 'deliveryNote'])->name('folders.delivery-note');
        Route::post('/folders/{id}/invoice', [FolderController::class, 'invoice'])->name('folders.invoice');

        Route::get('/vehicle-stock', [VehicleStockController::class, 'index'])->name('vehicle-stock.index');
        Route::post('/vehicle-stock', [VehicleStockController::class, 'store'])->name('vehicle-stock.store');
        Route::get('/vehicle-stock/{id}', [VehicleStockController::class, 'show'])->name('vehicle-stock.show');
        Route::put('/vehicle-stock/{id}', [VehicleStockController::class, 'update'])->name('vehicle-stock.update');
        Route::post('/vehicle-stock/{id}/sell', [VehicleStockController::class, 'sell'])->name('vehicle-stock.sell');

        Route::get('/recurring-services', [RecurringServiceController::class, 'index'])->name('recurring-services.index');
        Route::post('/recurring-services', [RecurringServiceController::class, 'store'])->name('recurring-services.store');
        Route::post('/recurring-services/{id}/stop', [RecurringServiceController::class, 'stop'])->name('recurring-services.stop');

        Route::get('/folders/{id}/time-entries', [LabourTimeController::class, 'index'])->name('folders.time.index');
        Route::post('/folders/{id}/time-entries', [LabourTimeController::class, 'store'])->name('folders.time.store');
        Route::post('/folders/{id}/time-entries/start', [LabourTimeController::class, 'start'])->name('folders.time.start');
        Route::post('/folders/{id}/time-entries/bill', [LabourTimeController::class, 'bill'])->name('folders.time.bill');
        Route::post('/time-entries/{id}/stop', [LabourTimeController::class, 'stop'])->name('time.stop');
        Route::get('/vehicles/{id}/history', [LabourTimeController::class, 'history'])->name('vehicles.history');

        Route::get('/bays', [SchedulingController::class, 'bays'])->name('bays.index');
        Route::post('/bays', [SchedulingController::class, 'storeBay'])->name('bays.store');
        Route::put('/bays/{id}', [SchedulingController::class, 'updateBay'])->name('bays.update');
        Route::get('/schedule', [SchedulingController::class, 'index'])->name('schedule.index');
        Route::post('/schedule', [SchedulingController::class, 'store'])->name('schedule.store');
        Route::put('/schedule/{id}', [SchedulingController::class, 'update'])->name('schedule.update');
        Route::post('/schedule/{id}/start', [SchedulingController::class, 'start'])->name('schedule.start');
        Route::post('/schedule/{id}/complete', [SchedulingController::class, 'complete'])->name('schedule.complete');
        Route::post('/schedule/{id}/cancel', [SchedulingController::class, 'cancel'])->name('schedule.cancel');

        Route::get('/estimates', [EstimateController::class, 'index'])->name('estimates.index');
        Route::post('/estimates', [EstimateController::class, 'store'])->name('estimates.store');
        Route::get('/estimates/{id}', [EstimateController::class, 'show'])->name('estimates.show');
        Route::put('/estimates/{id}', [EstimateController::class, 'update'])->name('estimates.update');
        Route::delete('/estimates/{id}', [EstimateController::class, 'destroy'])->name('estimates.destroy');
        Route::post('/estimates/{id}/lines', [EstimateController::class, 'storeLine'])->name('estimates.lines.store');
        Route::delete('/estimates/{id}/lines/{lineId}', [EstimateController::class, 'destroyLine'])->name('estimates.lines.destroy');
        Route::post('/estimates/{id}/send', [EstimateController::class, 'send'])->name('estimates.send');
        Route::post('/estimates/{id}/approve', [EstimateController::class, 'approve'])->name('estimates.approve');
        Route::post('/estimates/{id}/reject', [EstimateController::class, 'reject'])->name('estimates.reject');
        Route::post('/estimates/{id}/convert', [EstimateController::class, 'convert'])->name('estimates.convert');

        Route::get('/warranty-claims', [WarrantyClaimController::class, 'index'])->name('warranty-claims.index');
        Route::post('/warranty-claims', [WarrantyClaimController::class, 'store'])->name('warranty-claims.store');
        Route::get('/warranty-claims/{id}', [WarrantyClaimController::class, 'show'])->name('warranty-claims.show');
        Route::put('/warranty-claims/{id}', [WarrantyClaimController::class, 'update'])->name('warranty-claims.update');
        Route::post('/warranty-claims/{id}/submit', [WarrantyClaimController::class, 'submit'])->name('warranty-claims.submit');
        Route::post('/warranty-claims/{id}/accept', [WarrantyClaimController::class, 'accept'])->name('warranty-claims.accept');
        Route::post('/warranty-claims/{id}/reject', [WarrantyClaimController::class, 'reject'])->name('warranty-claims.reject');
        Route::post('/warranty-claims/{id}/reimburse', [WarrantyClaimController::class, 'reimburse'])->name('warranty-claims.reimburse');

        Route::get('/folders/{id}/parts', [PartConsumptionController::class, 'index'])->name('folders.parts.index');
        Route::post('/folders/{id}/parts', [PartConsumptionController::class, 'reserve'])->name('folders.parts.reserve');
        Route::get('/parts/{id}/availability', [PartConsumptionController::class, 'availability'])->name('parts.availability');
        Route::post('/part-consumptions/{id}/issue', [PartConsumptionController::class, 'issue'])->name('part-consumptions.issue');
        Route::post('/part-consumptions/{id}/release', [PartConsumptionController::class, 'release'])->name('part-consumptions.release');
    });

// Customer vehicles referenced by folders, recurring services and the tire hotel. The list is GET /api/v1/automotive/vehicles.
Route::prefix('api/v1/automotive')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:automotive'])
    ->name('api.v1.automotive.')
    ->where(['id' => '[0-9a-fA-F-]{36}'])
    ->group(function (): void {
        Route::post('/vehicles', [CustomerVehicleController::class, 'store'])->name('vehicles.store');
        Route::get('/vehicles/{id}', [CustomerVehicleController::class, 'show'])->name('vehicles.show');
        Route::put('/vehicles/{id}', [CustomerVehicleController::class, 'update'])->name('vehicles.update');
        Route::patch('/vehicles/{id}', [CustomerVehicleController::class, 'update'])->name('vehicles.patch');
        Route::delete('/vehicles/{id}', [CustomerVehicleController::class, 'destroy'])->name('vehicles.destroy');
    });
