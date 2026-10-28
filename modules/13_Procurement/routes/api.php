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
        // 1. Suppliers & Vendor Master
        Route::get('suppliers', [SupplierController::class, 'index'])->name('procurement.suppliers.index');
        Route::post('suppliers', [SupplierController::class, 'store'])->name('procurement.suppliers.store');
        Route::get('suppliers/{id}', [SupplierController::class, 'show'])->name('procurement.suppliers.show');
        Route::post('suppliers/{id}/evaluations', [SupplierEvaluationController::class, 'store'])->name('procurement.suppliers.evaluations.store');
        Route::get('suppliers/{id}/scorecard', [SupplierEvaluationController::class, 'scorecard'])->name('procurement.suppliers.scorecard');

        // 2. Supplier Contracts & Framework Agreements
        Route::get('contracts', [SupplierContractController::class, 'index'])->name('procurement.contracts.index');
        Route::get('contracts/{id}', [SupplierContractController::class, 'show'])->name('procurement.contracts.show');

        // 3. Purchase Requisitions
        Route::get('requisitions', [PurchaseRequisitionController::class, 'index'])->name('procurement.requisitions.index');
        Route::post('requisitions', [PurchaseRequisitionController::class, 'store'])->name('procurement.requisitions.store');
        Route::get('requisitions/{id}', [PurchaseRequisitionController::class, 'show'])->name('procurement.requisitions.show');
        Route::post('requisitions/{id}/submit-approval', [PurchaseRequisitionController::class, 'submitApproval'])->name('procurement.requisitions.submit-approval');

        // 4. Purchase Orders
        Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('procurement.orders.index');
        Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('procurement.orders.store');
        Route::get('purchase-orders/{id}', [PurchaseOrderController::class, 'show'])->name('procurement.orders.show');
        Route::post('purchase-orders/{id}/submit-approval', [PurchaseOrderController::class, 'submitApproval'])->name('procurement.orders.submit-approval');
        Route::post('purchase-orders/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->name('procurement.orders.cancel');

        // 5. Goods Receipts & Warehouse Receiving (GRN)
        Route::get('goods-receipts', [PurchaseReceiptController::class, 'index'])->name('procurement.receipts.index');
        Route::post('purchase-orders/{id}/goods-receipt', [PurchaseReceiptController::class, 'store'])->name('procurement.orders.goods-receipt');
        Route::get('goods-receipts/{id}', [PurchaseReceiptController::class, 'show'])->name('procurement.receipts.show');

        // 6. Purchase Returns
        Route::get('returns', [PurchaseReturnController::class, 'index'])->name('procurement.returns.index');
        Route::post('purchase-orders/{id}/returns', [PurchaseReturnController::class, 'store'])->name('procurement.orders.returns.store');

        // 7. Three-Way Matching Engine
        Route::get('three-way-match', [ThreeWayMatchController::class, 'index'])->name('procurement.three-way-match.index');
        Route::post('three-way-match/execute', [ThreeWayMatchController::class, 'execute'])->name('procurement.three-way-match.execute');
    });
