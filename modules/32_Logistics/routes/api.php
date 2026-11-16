<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Logistics\Http\Controllers\ConsignmentController;
use Modules\Logistics\Http\Controllers\DeliveryNoteController;
use Modules\Logistics\Http\Controllers\LogisticsLookupController;
use Modules\Logistics\Http\Controllers\StockTransferController;
use Modules\Logistics\Support\LogisticsPermissions as P;

Route::prefix('api/v1/logistics')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'logistics.envelope'])
    ->name('logistics.')
    ->group(function (): void {
        // Lookups for the screens
        Route::get('lookups/warehouses', [LogisticsLookupController::class, 'warehouses'])->middleware('logistics.can:'.P::DDT_VIEW)->name('lookups.warehouses');
        Route::get('lookups/products', [LogisticsLookupController::class, 'products'])->middleware('logistics.can:'.P::DDT_VIEW)->name('lookups.products');
        Route::get('lookups/partners', [LogisticsLookupController::class, 'partners'])->middleware('logistics.can:'.P::DDT_VIEW)->name('lookups.partners');
        Route::get('lookups/open-orders', [LogisticsLookupController::class, 'openOrders'])->middleware('logistics.can:'.P::DDT_CREATE)->name('lookups.open-orders');

        // DDT
        Route::get('delivery-notes', [DeliveryNoteController::class, 'index'])->middleware('logistics.can:'.P::DDT_VIEW)->name('ddt.index');
        Route::get('delivery-notes/unbilled', [DeliveryNoteController::class, 'unbilled'])->middleware('logistics.can:'.P::DDT_INVOICE)->name('ddt.unbilled');
        Route::get('delivery-notes/{id}', [DeliveryNoteController::class, 'show'])->middleware('logistics.can:'.P::DDT_VIEW)->name('ddt.show');
        Route::get('delivery-notes/{id}/pdf', [DeliveryNoteController::class, 'pdf'])->middleware('logistics.can:'.P::DDT_PDF)->name('ddt.pdf');
        Route::post('delivery-notes', [DeliveryNoteController::class, 'store'])->middleware('logistics.can:'.P::DDT_CREATE)->name('ddt.store');
        Route::post('delivery-notes/from-orders', [DeliveryNoteController::class, 'fromOrders'])->middleware('logistics.can:'.P::DDT_CREATE)->name('ddt.from-orders');
        Route::post('delivery-notes/deferred-invoice', [DeliveryNoteController::class, 'deferredInvoice'])->middleware('logistics.can:'.P::DDT_INVOICE)->name('ddt.deferred-invoice');
        Route::post('delivery-notes/deferred-invoicing/run', [DeliveryNoteController::class, 'runDeferredInvoicing'])->middleware('logistics.can:'.P::DDT_INVOICE)->name('ddt.deferred-invoicing.run');
        Route::put('delivery-notes/{id}', [DeliveryNoteController::class, 'update'])->middleware('logistics.can:'.P::DDT_UPDATE)->name('ddt.update');
        Route::delete('delivery-notes/{id}', [DeliveryNoteController::class, 'destroy'])->middleware('logistics.can:'.P::DDT_DELETE)->name('ddt.destroy');
        Route::post('delivery-notes/{id}/issue', [DeliveryNoteController::class, 'issue'])->middleware('logistics.can:'.P::DDT_ISSUE)->name('ddt.issue');
        Route::post('delivery-notes/{id}/dispatch', [DeliveryNoteController::class, 'dispatch'])->middleware('logistics.can:'.P::DDT_ISSUE)->name('ddt.dispatch');
        Route::post('delivery-notes/{id}/deliver', [DeliveryNoteController::class, 'deliver'])->middleware('logistics.can:'.P::DDT_ISSUE)->name('ddt.deliver');
        Route::post('delivery-notes/{id}/cancel', [DeliveryNoteController::class, 'cancel'])->middleware('logistics.can:'.P::DDT_CANCEL)->name('ddt.cancel');

    });

/*
 * WMS transfers, discrepancies and consignment: served under the versioned WMS prefix (/api/v1/wms) and, for existing
 * clients, under the legacy logistics prefix (/api/v1/logistics) with the same controllers and RBAC.
 */
foreach (['wms' => 'api/v1/wms', 'logistics' => 'api/v1/logistics'] as $name => $prefix) {
    Route::prefix($prefix)
        ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'logistics.envelope'])
        ->name($name === 'wms' ? 'wms.' : 'logistics.legacy.')
        ->group(function (): void {
            // Transfers (two-step) and discrepancies
            Route::middleware('logistics.can:'.P::TRANSFERS_MANAGE)->group(function (): void {
                Route::get('transfers', [StockTransferController::class, 'index'])->name('transfers.index');
                Route::post('transfers', [StockTransferController::class, 'store'])->name('transfers.store');
                Route::get('transfers/{id}', [StockTransferController::class, 'show'])->name('transfers.show');
                Route::post('transfers/{id}/ship', [StockTransferController::class, 'ship'])->name('transfers.ship');
                Route::post('transfers/{id}/receive', [StockTransferController::class, 'receive'])->name('transfers.receive');
                Route::post('transfers/{id}/scan', [StockTransferController::class, 'scan'])->name('transfers.scan');
                Route::post('transfers/{id}/cancel', [StockTransferController::class, 'cancel'])->name('transfers.cancel');
                Route::get('transfer-discrepancies', [StockTransferController::class, 'discrepancies'])->name('discrepancies.index');
            });
            Route::middleware('logistics.can:'.P::TRANSFERS_APPROVE)->group(function (): void {
                Route::post('transfers/{id}/approve', [StockTransferController::class, 'approve'])->name('transfers.approve');
                Route::post('transfers/{id}/close', [StockTransferController::class, 'close'])->name('transfers.close');
                Route::post('transfers/{id}/discrepancies/{discrepancyId}/resolve', [StockTransferController::class, 'resolveTransferDiscrepancy'])->name('transfers.discrepancies.resolve');
                Route::post('transfer-discrepancies/{id}/resolve', [StockTransferController::class, 'resolveDiscrepancy'])->name('discrepancies.resolve');
            });

            // Consignment / third-party goods
            Route::middleware('logistics.can:'.P::DDT_VIEW)->group(function (): void {
                Route::get('consignment/ledger', [ConsignmentController::class, 'ledger'])->name('consignment.ledger');
                Route::get('consignment/valuation', [ConsignmentController::class, 'valuation'])->name('consignment.valuation');
                Route::get('reports/inventory-valuation', [ConsignmentController::class, 'valuation'])->name('reports.inventory-valuation');
                Route::get('reports/third-party-register', [ConsignmentController::class, 'register'])->name('reports.third-party-register');
                Route::get('consignment/declarations', [ConsignmentController::class, 'declarations'])->name('consignment.declarations');
                Route::get('consignment/consumption-reports', [ConsignmentController::class, 'declarations'])->name('consignment.reports');
                Route::get('consignment/agreements', [ConsignmentController::class, 'agreements'])->name('consignment.agreements');
            });
            Route::middleware('logistics.can:'.P::CONSIGNMENT_RECONCILE)->group(function (): void {
                Route::post('consignment/declarations', [ConsignmentController::class, 'declare'])->name('consignment.declare');
                Route::post('consignment/consumption-reports', [ConsignmentController::class, 'declare'])->name('consignment.declare-legacy');
                Route::post('consignment/vendor-receipts', [ConsignmentController::class, 'vendorReceipt'])->name('consignment.vendor-receipts');
                Route::post('consignment/agreements', [ConsignmentController::class, 'storeAgreement'])->name('consignment.agreements.store');
                Route::post('consignment/agreements/{id}/terminate', [ConsignmentController::class, 'terminateAgreement'])->name('consignment.agreements.terminate');
            });
        });
}
