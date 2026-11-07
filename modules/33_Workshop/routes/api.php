<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Workshop\Http\Controllers\CustomerVehicleController;
use Modules\Workshop\Http\Controllers\FolderController;
use Modules\Workshop\Http\Controllers\RecurringServiceController;
use Modules\Workshop\Http\Controllers\VehicleStockController;

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
