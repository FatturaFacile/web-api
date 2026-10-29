<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Procurement\Http\Controllers\PurchaseOrderController;
use Modules\Procurement\Http\Controllers\PurchaseReceiptController;
use Modules\Procurement\Http\Controllers\PurchaseRequisitionController;
use Modules\Procurement\Http\Controllers\PurchaseReturnController;
use Modules\Procurement\Http\Controllers\SupplierContractController;
use Modules\Procurement\Http\Controllers\SupplierController;
use Modules\Procurement\Http\Controllers\SupplierEvaluationController;
use Modules\Procurement\Http\Controllers\ThreeWayMatchController;

Route::prefix('api/v1/procurement')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        // 1. Suppliers & Vendor Master (Full REST API Resource + Custom Lifecycle)
        Route::apiResource('suppliers', SupplierController::class)->names('procurement.suppliers');
        Route::get('evaluations', [SupplierEvaluationController::class, 'index'])->name('procurement.evaluations.index');
        Route::post('suppliers/{id}/evaluations', [SupplierEvaluationController::class, 'store'])->name('procurement.suppliers.evaluations.store');
        Route::get('suppliers/{id}/scorecard', [SupplierEvaluationController::class, 'scorecard'])->name('procurement.suppliers.scorecard');

        // 2. Supplier Contracts & Framework Agreements (Full REST API Resource)
        Route::apiResource('contracts', SupplierContractController::class)->names('procurement.contracts');

        // 3. Purchase Requisitions (Full REST API Resource + Approval Actions)
        Route::apiResource('requisitions', PurchaseRequisitionController::class)->names('procurement.requisitions');
        Route::post('requisitions/{id}/submit-approval', [PurchaseRequisitionController::class, 'submitApproval'])->name('procurement.requisitions.submit-approval');
        Route::post('requisitions/{id}/approve', [PurchaseRequisitionController::class, 'approve'])->name('procurement.requisitions.approve');
        Route::post('requisitions/{id}/reject', [PurchaseRequisitionController::class, 'reject'])->name('procurement.requisitions.reject');
        Route::post('requisitions/{id}/cancel', [PurchaseRequisitionController::class, 'cancel'])->name('procurement.requisitions.cancel');

        // 4. Purchase Orders (Full REST API Resource + Custom Lifecycle Operations)
        Route::apiResource('purchase-orders', PurchaseOrderController::class)->names('procurement.orders');
        Route::post('purchase-orders/{id}/submit-approval', [PurchaseOrderController::class, 'submitApproval'])->name('procurement.orders.submit-approval');
        Route::post('purchase-orders/{id}/approve', [PurchaseOrderController::class, 'approve'])->name('procurement.orders.approve');
        Route::post('purchase-orders/{id}/reject', [PurchaseOrderController::class, 'reject'])->name('procurement.orders.reject');
        Route::post('purchase-orders/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->name('procurement.orders.cancel');
        Route::post('purchase-orders/{id}/close', [PurchaseOrderController::class, 'close'])->name('procurement.orders.close');
        Route::post('purchase-orders/{id}/reopen', [PurchaseOrderController::class, 'reopen'])->name('procurement.orders.reopen');

        // 5. Goods Receipts & Warehouse Receiving (GRN)
        Route::get('goods-receipts', [PurchaseReceiptController::class, 'index'])->name('procurement.receipts.index');
        Route::post('purchase-orders/{id}/goods-receipt', [PurchaseReceiptController::class, 'store'])->name('procurement.orders.goods-receipt');
        Route::get('goods-receipts/{id}', [PurchaseReceiptController::class, 'show'])->name('procurement.receipts.show');

        // 6. Purchase Returns (RTV)
        Route::get('returns', [PurchaseReturnController::class, 'index'])->name('procurement.returns.index');
        Route::post('purchase-orders/{id}/returns', [PurchaseReturnController::class, 'store'])->name('procurement.orders.returns.store');
        Route::get('returns/{id}', [PurchaseReturnController::class, 'show'])->name('procurement.returns.show');

        // 7. Three-Way Matching Engine
        Route::get('three-way-match', [ThreeWayMatchController::class, 'index'])->name('procurement.three-way-match.index');
        Route::post('three-way-match/execute', [ThreeWayMatchController::class, 'execute'])->name('procurement.three-way-match.execute');
    });
