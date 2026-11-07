<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\BarcodeController;
use Modules\Inventory\Http\Controllers\CustomerPortalStockController;
use Modules\Inventory\Http\Controllers\InventoryAdjustmentController;
use Modules\Inventory\Http\Controllers\InventoryCountController;
use Modules\Inventory\Http\Controllers\PortalMovementRequestController;
use Modules\Inventory\Http\Controllers\ProductChargeController;
use Modules\Inventory\Http\Controllers\RepairOrderController;
use Modules\Inventory\Http\Controllers\SafetyDataSheetController;
use Modules\Inventory\Http\Controllers\ShippingController;
use Modules\Inventory\Http\Controllers\StockItemController;
use Modules\Inventory\Http\Controllers\StockLabelController;
use Modules\Inventory\Http\Controllers\StockLotController;
use Modules\Inventory\Http\Controllers\StockMovementController;
use Modules\Inventory\Http\Controllers\StockPackingController;
use Modules\Inventory\Http\Controllers\StockPickListController;
use Modules\Inventory\Http\Controllers\StockQuantController;
use Modules\Inventory\Http\Controllers\StockReservationController;
use Modules\Inventory\Http\Controllers\StockValuationController;
use Modules\Inventory\Http\Controllers\WarehouseController;
use Modules\Inventory\Http\Controllers\WarehouseLocationController;
use Modules\Inventory\Http\Controllers\WarehouseTerminalController;
use Modules\Inventory\Http\Controllers\WeighbridgeController;

Route::prefix('api/v1/inventory')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:inventory'])
    ->group(function (): void {
        // 1. Warehouses & Physical/Mobile Sites
        Route::apiResource('warehouses', WarehouseController::class)->names('inventory.warehouses');

        // 2. Stock Item Master Catalog & SKUs
        Route::apiResource('items', StockItemController::class)->names('inventory.items');

        // 3. Stock Quants (Real-time Balances by Bin/Lot/Serial)
        Route::get('quants', [StockQuantController::class, 'index'])->name('inventory.quants.index');
        Route::get('quants/as-of', [StockQuantController::class, 'asOf'])->name('inventory.quants.as-of');
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
        Route::get('pick-lists/{id}', [StockPickListController::class, 'show'])->name('inventory.pick-lists.show');
        Route::get('pick-lists/{id}/next-stop', [StockPickListController::class, 'nextStop'])->name('inventory.pick-lists.next-stop');
        Route::post('pick-lists/{id}/lines/{lineId}/confirm', [StockPickListController::class, 'confirmLine'])->name('inventory.pick-lists.lines.confirm');
        Route::post('pick-lists/{id}/cancel', [StockPickListController::class, 'cancel'])->name('inventory.pick-lists.cancel');
        Route::post('pick-lists/{id}/packing-list', [StockPackingController::class, 'store'])->name('inventory.pick-lists.packing-list');

        // 8b. Packing Lists, Cartons and Pallets
        Route::post('packing/calculate', [StockPackingController::class, 'calculate'])->name('inventory.packing.calculate');
        Route::get('packing-lists/{id}', [StockPackingController::class, 'show'])->name('inventory.packing-lists.show');

        // 8c. Handheld Barcode Terminal
        Route::post('terminal/receipt', [WarehouseTerminalController::class, 'receipt'])->name('inventory.terminal.receipt');
        Route::post('terminal/pick', [WarehouseTerminalController::class, 'pick'])->name('inventory.terminal.pick');
        Route::post('terminal/transfer', [WarehouseTerminalController::class, 'transfer'])->name('inventory.terminal.transfer');
        Route::post('terminal/count', [WarehouseTerminalController::class, 'count'])->name('inventory.terminal.count');

        // 8d. Carriers, Shipments and Tracking
        Route::get('carriers', [ShippingController::class, 'carriers'])->name('inventory.carriers.index');
        Route::post('carriers', [ShippingController::class, 'storeCarrier'])->name('inventory.carriers.store');
        Route::post('shipments', [ShippingController::class, 'storeShipment'])->name('inventory.shipments.store');
        Route::get('shipments/{id}', [ShippingController::class, 'showShipment'])->name('inventory.shipments.show');
        Route::get('shipments/{id}/label', [ShippingController::class, 'label'])->name('inventory.shipments.label');
        Route::post('shipments/{id}/tracking', [ShippingController::class, 'addTrackingEvent'])->name('inventory.shipments.tracking');
        Route::post('shipments/{id}/refresh', [ShippingController::class, 'refresh'])->name('inventory.shipments.refresh');

        // 8e. Safety Data Sheets
        Route::get('safety-sheets', [SafetyDataSheetController::class, 'index'])->name('inventory.safety-sheets.index');
        Route::post('safety-sheets', [SafetyDataSheetController::class, 'store'])->name('inventory.safety-sheets.store');
        Route::get('safety-sheets/versions/{versionId}/file', [SafetyDataSheetController::class, 'download'])->name('inventory.safety-sheets.versions.file');
        Route::get('safety-sheets/deliveries/{deliveryNoteId}', [SafetyDataSheetController::class, 'deliveries'])->name('inventory.safety-sheets.deliveries');
        Route::post('safety-sheets/deliveries/{deliveryNoteId}/send', [SafetyDataSheetController::class, 'send'])->name('inventory.safety-sheets.deliveries.send');
        Route::get('safety-sheets/{id}/versions', [SafetyDataSheetController::class, 'versions'])->name('inventory.safety-sheets.versions.index');
        Route::post('safety-sheets/{id}/versions', [SafetyDataSheetController::class, 'addVersion'])->name('inventory.safety-sheets.versions.store');

        // 8f. Weighbridge
        Route::get('weighbridge/vehicles', [WeighbridgeController::class, 'vehicles'])->name('inventory.weighbridge.vehicles.index');
        Route::post('weighbridge/vehicles', [WeighbridgeController::class, 'storeVehicle'])->name('inventory.weighbridge.vehicles.store');
        Route::post('weighbridge/readings', [WeighbridgeController::class, 'storeReading'])->name('inventory.weighbridge.readings.store');
        Route::get('weighbridge/readings/latest', [WeighbridgeController::class, 'latestReading'])->name('inventory.weighbridge.readings.latest');
        Route::get('weighbridge/tickets', [WeighbridgeController::class, 'tickets'])->name('inventory.weighbridge.tickets.index');
        Route::post('weighbridge/tickets', [WeighbridgeController::class, 'open'])->name('inventory.weighbridge.tickets.store');
        Route::post('weighbridge/tickets/{id}/second-weighing', [WeighbridgeController::class, 'secondWeighing'])->name('inventory.weighbridge.tickets.second-weighing');
        Route::post('weighbridge/tickets/{id}/bill', [WeighbridgeController::class, 'bill'])->name('inventory.weighbridge.tickets.bill');

        // 8g. Customer Portal Requests
        Route::get('portal-requests', [PortalMovementRequestController::class, 'index'])->name('inventory.portal-requests.index');
        Route::post('portal-requests/{id}/approve', [PortalMovementRequestController::class, 'approve'])->name('inventory.portal-requests.approve');
        Route::post('portal-requests/{id}/reject', [PortalMovementRequestController::class, 'reject'])->name('inventory.portal-requests.reject');

        // 8h. Warranties and Repairs
        Route::get('warranties/lookup', [RepairOrderController::class, 'lookup'])->name('inventory.warranties.lookup');
        Route::put('serials/{id}/warranty', [RepairOrderController::class, 'recordWarranty'])->name('inventory.serials.warranty');
        Route::get('repair-orders', [RepairOrderController::class, 'index'])->name('inventory.repair-orders.index');
        Route::post('repair-orders', [RepairOrderController::class, 'store'])->name('inventory.repair-orders.store');
        Route::get('repair-orders/{id}', [RepairOrderController::class, 'show'])->name('inventory.repair-orders.show');
        Route::post('repair-orders/{id}/status', [RepairOrderController::class, 'advance'])->name('inventory.repair-orders.status');
        Route::post('repair-orders/{id}/parts', [RepairOrderController::class, 'addPart'])->name('inventory.repair-orders.parts');
        Route::post('repair-orders/{id}/invoice', [RepairOrderController::class, 'invoice'])->name('inventory.repair-orders.invoice');

        // 8i. Barcode Labels
        Route::post('labels/barcode', [StockLabelController::class, 'barcode'])->name('inventory.labels.barcode');

        // 9. Barcode Scanning (GS1-128 / EAN-13 / Serials / Bins)
        Route::post('barcode/scan', [BarcodeController::class, 'scan'])->name('inventory.barcode.scan');

        // 9b. Warehouse Locations
        Route::post('warehouses/{id}/locations', [WarehouseLocationController::class, 'store'])->name('inventory.warehouses.locations.store');

        // 9c. Lots: Expiry, Quarantine, Genealogy and Batch Stock
        Route::get('lots', [StockLotController::class, 'index'])->name('inventory.lots.index');
        Route::post('lots', [StockLotController::class, 'store'])->name('inventory.lots.store');
        Route::get('lots/stock', [StockLotController::class, 'stock'])->name('inventory.lots.stock');
        Route::get('lots/{id}', [StockLotController::class, 'show'])->name('inventory.lots.show');
        Route::patch('lots/{id}', [StockLotController::class, 'update'])->name('inventory.lots.update');
        Route::post('lots/{id}/components', [StockLotController::class, 'addComponent'])->name('inventory.lots.components.store');
        Route::get('lots/{id}/traceability', [StockLotController::class, 'traceability'])->name('inventory.lots.traceability');
        Route::post('lots/{id}/quarantine', [StockLotController::class, 'quarantine'])->name('inventory.lots.quarantine');
        Route::post('lots/{id}/release', [StockLotController::class, 'release'])->name('inventory.lots.release');
        Route::get('availability', [StockLotController::class, 'availability'])->name('inventory.availability');

        // 10. Valuation (FIFO / AVCO / Standard Costing)
        Route::get('valuation/summary', [StockValuationController::class, 'summary'])->name('inventory.valuation.summary');
        Route::post('valuation/snapshot', [StockValuationController::class, 'captureSnapshot'])->name('inventory.valuation.snapshot');
    });

Route::prefix('api/v1/invoicing')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:invoicing'])
    ->group(function (): void {
        Route::get('product-charges', [ProductChargeController::class, 'index'])->name('invoicing.product-charges.index');
        Route::post('product-charges', [ProductChargeController::class, 'store'])->name('invoicing.product-charges.store');
        Route::post('product-charges/preview', [ProductChargeController::class, 'preview'])->name('invoicing.product-charges.preview');
        Route::post('product-charges/{id}/rates', [ProductChargeController::class, 'storeRate'])->name('invoicing.product-charges.rates.store');
        Route::post('product-charges/{id}/products', [ProductChargeController::class, 'assign'])->name('invoicing.product-charges.products.store');
    });

// The customer portal is public: the magic link in the path is the credential.
Route::prefix('api/v1/portal')
    ->middleware(['api', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('stock/{token}', [CustomerPortalStockController::class, 'stock'])->name('portal.stock');
        Route::get('stock-movements/{token}', [CustomerPortalStockController::class, 'movements'])->name('portal.stock-movements');
        Route::get('movement-requests/{token}', [CustomerPortalStockController::class, 'requests'])->name('portal.movement-requests.index');
        Route::post('movement-requests/{token}', [CustomerPortalStockController::class, 'storeRequest'])->name('portal.movement-requests.store');
    });
