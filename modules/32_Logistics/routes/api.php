<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Logistics\Http\Controllers\ConsignmentController;
use Modules\Logistics\Http\Controllers\DeliveryNoteController;
use Modules\Logistics\Http\Controllers\StockTransferController;
use Modules\Logistics\Support\LogisticsPermissions as P;

Route::prefix('api/v1/logistics')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])
    ->name('logistics.')
    ->group(function (): void {
        // DDT
        Route::get('delivery-notes', [DeliveryNoteController::class, 'index'])->middleware('logistics.can:'.P::DDT_VIEW)->name('ddt.index');
        Route::get('delivery-notes/{id}', [DeliveryNoteController::class, 'show'])->middleware('logistics.can:'.P::DDT_VIEW)->name('ddt.show');
        Route::get('delivery-notes/{id}/pdf', [DeliveryNoteController::class, 'pdf'])->middleware('logistics.can:'.P::DDT_VIEW)->name('ddt.pdf');
        Route::post('delivery-notes', [DeliveryNoteController::class, 'store'])->middleware('logistics.can:'.P::DDT_CREATE)->name('ddt.store');
        Route::post('delivery-notes/from-orders', [DeliveryNoteController::class, 'fromOrders'])->middleware('logistics.can:'.P::DDT_CREATE)->name('ddt.from-orders');
        Route::post('delivery-notes/deferred-invoice', [DeliveryNoteController::class, 'deferredInvoice'])->middleware('logistics.can:'.P::DDT_EMIT)->name('ddt.deferred-invoice');
        Route::post('delivery-notes/{id}/issue', [DeliveryNoteController::class, 'issue'])->middleware('logistics.can:'.P::DDT_EMIT)->name('ddt.issue');
        Route::post('delivery-notes/{id}/deliver', [DeliveryNoteController::class, 'deliver'])->middleware('logistics.can:'.P::DDT_EMIT)->name('ddt.deliver');
        Route::post('delivery-notes/{id}/cancel', [DeliveryNoteController::class, 'cancel'])->middleware('logistics.can:'.P::DDT_CANCEL)->name('ddt.cancel');

        // Transfers (two-step), discrepancies
        Route::middleware('logistics.can:'.P::TRANSFERS_MANAGE)->group(function (): void {
            Route::get('transfers', [StockTransferController::class, 'index'])->name('transfers.index');
            Route::post('transfers', [StockTransferController::class, 'store'])->name('transfers.store');
            Route::get('transfers/{id}', [StockTransferController::class, 'show'])->name('transfers.show');
            Route::post('transfers/{id}/ship', [StockTransferController::class, 'ship'])->name('transfers.ship');
            Route::post('transfers/{id}/receive', [StockTransferController::class, 'receive'])->name('transfers.receive');
            Route::post('transfers/{id}/cancel', [StockTransferController::class, 'cancel'])->name('transfers.cancel');
            Route::get('transfer-discrepancies', [StockTransferController::class, 'discrepancies'])->name('discrepancies.index');
            Route::post('transfer-discrepancies/{id}/resolve', [StockTransferController::class, 'resolveDiscrepancy'])->name('discrepancies.resolve');
        });

        // Consignment / third-party goods
        Route::get('consignment/ledger', [ConsignmentController::class, 'ledger'])->middleware('logistics.can:'.P::DDT_VIEW)->name('consignment.ledger');
        Route::get('consignment/valuation', [ConsignmentController::class, 'valuation'])->middleware('logistics.can:'.P::DDT_VIEW)->name('consignment.valuation');
        Route::get('consignment/consumption-reports', [ConsignmentController::class, 'reports'])->middleware('logistics.can:'.P::DDT_VIEW)->name('consignment.reports');
        Route::post('consignment/consumption-reports', [ConsignmentController::class, 'declare'])->middleware('logistics.can:'.P::CONSIGNMENT_RECONCILE)->name('consignment.declare');
    });
