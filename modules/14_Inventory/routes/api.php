<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\BarcodeController;
use Modules\Inventory\Http\Controllers\InventoryAdjustmentController;
use Modules\Inventory\Http\Controllers\InventoryCountController;
use Modules\Inventory\Http\Controllers\StockItemController;
use Modules\Inventory\Http\Controllers\StockMovementController;
use Modules\Inventory\Http\Controllers\StockPickListController;
use Modules\Inventory\Http\Controllers\StockQuantController;
use Modules\Inventory\Http\Controllers\StockReservationController;
use Modules\Inventory\Http\Controllers\StockValuationController;
use Modules\Inventory\Http\Controllers\WarehouseController;

Route::prefix('api/v1/inventory')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        // 1. Warehouses & Physical/Mobile Sites
        Route::apiResource('warehouses', WarehouseController::class)->names('inventory.warehouses');

        // 2. Stock Item Master Catalog & SKUs
        Route::apiResource('items', StockItemController::class)->names('inventory.items');

        // 3. Stock Quants (Real-time Balances by Bin/Lot/Serial)
        Route::get('quants', [StockQuantController::class, 'index'])->name('inventory.quants.index');
        Route::get('quants/{id}', [StockQuantController::class, 'show'])->name('inventory.quants.show');

        // 4. Double-Entry Movements & Transfers
        Route::get('movements', [StockMovementController::class, 'index'])->name('inventory.movements.index');
        Route::post('movements/transfer', [StockMovementController::class, 'transfer'])->name('inventory.movements.transfer');

        // 5. Hard & Soft Stock Reservations
        Route::get('reservations', [StockReservationController::class, 'index'])->name('inventory.reservations.index');
        Route::post('reservations', [StockReservationController::class, 'store'])->name('inventory.reservations.store');
        Route::post('reservations/{id}/release', [StockReservationController::class, 'release'])->name('inventory.reservations.release');

        // 6. Adjustments & Write-Offs (with Workflow Engine V2 Routing)
        Route::get('adjustments', [InventoryAdjustmentController::class, 'index'])->name('inventory.adjustments.index');
        Route::post('adjustments', [InventoryAdjustmentController::class, 'store'])->name('inventory.adjustments.store');
        Route::get('adjustments/{id}', [InventoryAdjustmentController::class, 'show'])->name('inventory.adjustments.show');
        Route::post('adjustments/{id}/approve', [InventoryAdjustmentController::class, 'approve'])->name('inventory.adjustments.approve');
        Route::post('adjustments/{id}/reject', [InventoryAdjustmentController::class, 'reject'])->name('inventory.adjustments.reject');

        // 7. Physical Stocktaking & ABC Cycle Counts
        Route::get('counts', [InventoryCountController::class, 'index'])->name('inventory.counts.index');
        Route::post('counts', [InventoryCountController::class, 'store'])->name('inventory.counts.store');
        Route::get('counts/{id}', [InventoryCountController::class, 'show'])->name('inventory.counts.show');
        Route::post('counts/{id}/record-count', [InventoryCountController::class, 'recordCount'])->name('inventory.counts.record-count');
        Route::post('counts/{id}/reconcile', [InventoryCountController::class, 'reconcile'])->name('inventory.counts.reconcile');

        // 8. Picking Lists & Warehouse Routing
        Route::get('pick-lists', [StockPickListController::class, 'index'])->name('inventory.pick-lists.index');
        Route::post('pick-lists', [StockPickListController::class, 'store'])->name('inventory.pick-lists.store');

        // 9. Barcode Scanning (GS1-128 / EAN-13 / Serials / Bins)
        Route::post('barcode/scan', [BarcodeController::class, 'scan'])->name('inventory.barcode.scan');

        // 10. Valuation (FIFO / AVCO / Standard Costing)
        Route::get('valuation/summary', [StockValuationController::class, 'summary'])->name('inventory.valuation.summary');
        Route::post('valuation/snapshot', [StockValuationController::class, 'captureSnapshot'])->name('inventory.valuation.snapshot');
    });
