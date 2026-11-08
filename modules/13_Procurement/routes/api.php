<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Procurement\Http\Controllers\FrameworkAgreementController;
use Modules\Procurement\Http\Controllers\InvoiceApprovalController;
use Modules\Procurement\Http\Controllers\LandedCostController;
use Modules\Procurement\Http\Controllers\ProcurementAnalyticsController;
use Modules\Procurement\Http\Controllers\ProcurementConfigurationController;
use Modules\Procurement\Http\Controllers\ProcurementOperationsController;
use Modules\Procurement\Http\Controllers\PurchaseOrderController;
use Modules\Procurement\Http\Controllers\PurchasePriceCheckController;
use Modules\Procurement\Http\Controllers\PurchaseReceiptController;
use Modules\Procurement\Http\Controllers\PurchaseRequisitionController;
use Modules\Procurement\Http\Controllers\PurchaseReturnController;
use Modules\Procurement\Http\Controllers\PurchaseSuggestionController;
use Modules\Procurement\Http\Controllers\RfqController;
use Modules\Procurement\Http\Controllers\SupplierCatalogController;
use Modules\Procurement\Http\Controllers\SupplierContractController;
use Modules\Procurement\Http\Controllers\SupplierController;
use Modules\Procurement\Http\Controllers\SupplierEvaluationController;
use Modules\Procurement\Http\Controllers\SupplierQualificationController;
use Modules\Procurement\Http\Controllers\ThreeWayMatchController;

Route::prefix('api/v1/procurement')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:procurement'])
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
        Route::post('price-checks', PurchasePriceCheckController::class)->name('procurement.price-checks');
        Route::post('three-way-match/execute', [ThreeWayMatchController::class, 'execute'])->name('procurement.three-way-match.execute');

        // 8. Configuration: tolerances and approval matrices of orders and requisitions
        Route::get('settings', [ProcurementConfigurationController::class, 'showSettings'])->name('procurement.settings.show');
        Route::put('settings', [ProcurementConfigurationController::class, 'updateSettings'])->name('procurement.settings.update');
        Route::get('approval-matrices', [ProcurementConfigurationController::class, 'matrices'])->name('procurement.approval-matrices.index');
        Route::post('approval-matrices', [ProcurementConfigurationController::class, 'storeMatrix'])->name('procurement.approval-matrices.store');
        Route::delete('approval-matrices/{id}', [ProcurementConfigurationController::class, 'destroyMatrix'])->whereUuid('id')->name('procurement.approval-matrices.destroy');

        // 9. Supplier catalogue and price lists
        Route::get('catalogs', [SupplierCatalogController::class, 'index'])->name('procurement.catalogs.index');
        Route::post('catalogs', [SupplierCatalogController::class, 'store'])->name('procurement.catalogs.store');
        Route::get('catalogs/{id}', [SupplierCatalogController::class, 'show'])->whereUuid('id')->name('procurement.catalogs.show');
        Route::put('catalogs/{id}', [SupplierCatalogController::class, 'update'])->whereUuid('id')->name('procurement.catalogs.update');
        Route::post('catalogs/{id}/prices', [SupplierCatalogController::class, 'storePrice'])->whereUuid('id')->name('procurement.catalogs.prices.store');
        Route::get('catalogs/{id}/price', [SupplierCatalogController::class, 'price'])->whereUuid('id')->name('procurement.catalogs.price');
        Route::get('offers', [SupplierCatalogController::class, 'offers'])->name('procurement.offers');

        // 10. Supplier qualification, documents and performance
        Route::put('suppliers/{id}/qualification', [SupplierQualificationController::class, 'updateQualification'])->whereUuid('id')->name('procurement.suppliers.qualification');
        Route::get('suppliers/{id}/documents', [SupplierQualificationController::class, 'documents'])->whereUuid('id')->name('procurement.suppliers.documents.index');
        Route::post('suppliers/{id}/documents', [SupplierQualificationController::class, 'storeDocument'])->whereUuid('id')->name('procurement.suppliers.documents.store');
        Route::get('supplier-documents/expiring', [SupplierQualificationController::class, 'expiring'])->name('procurement.supplier-documents.expiring');
        Route::delete('supplier-documents/{id}', [SupplierQualificationController::class, 'destroyDocument'])->whereUuid('id')->name('procurement.supplier-documents.destroy');
        Route::post('supplier-qualifications/expire', [SupplierQualificationController::class, 'expire'])->name('procurement.supplier-qualifications.expire');
        Route::get('suppliers/{id}/performance', [SupplierQualificationController::class, 'performance'])->whereUuid('id')->name('procurement.suppliers.performance');
        Route::post('suppliers/{id}/evaluations/compute', [SupplierQualificationController::class, 'computeEvaluation'])->whereUuid('id')->name('procurement.suppliers.evaluations.compute');

        // 11. Requests for quotation
        Route::get('rfqs', [RfqController::class, 'index'])->name('procurement.rfqs.index');
        Route::post('rfqs', [RfqController::class, 'store'])->name('procurement.rfqs.store');
        Route::get('rfqs/{id}', [RfqController::class, 'show'])->whereUuid('id')->name('procurement.rfqs.show');
        Route::post('rfqs/{id}/issue', [RfqController::class, 'issue'])->whereUuid('id')->name('procurement.rfqs.issue');
        Route::post('rfqs/{id}/quotes', [RfqController::class, 'storeQuote'])->whereUuid('id')->name('procurement.rfqs.quotes.store');
        Route::get('rfqs/{id}/comparison', [RfqController::class, 'comparison'])->whereUuid('id')->name('procurement.rfqs.comparison');
        Route::post('rfqs/{id}/award', [RfqController::class, 'award'])->whereUuid('id')->name('procurement.rfqs.award');
        Route::post('rfqs/{id}/cancel', [RfqController::class, 'cancel'])->whereUuid('id')->name('procurement.rfqs.cancel');

        // 12. Framework agreements and call-offs
        Route::get('contracts/{id}/lines', [FrameworkAgreementController::class, 'lines'])->whereUuid('id')->name('procurement.contracts.lines.index');
        Route::post('contracts/{id}/lines', [FrameworkAgreementController::class, 'storeLine'])->whereUuid('id')->name('procurement.contracts.lines.store');
        Route::post('contracts/{id}/call-offs', [FrameworkAgreementController::class, 'callOff'])->whereUuid('id')->name('procurement.contracts.call-offs.store');

        // 13. Operations: send, inspection, return credit note, match variance
        Route::post('purchase-orders/{id}/send', [ProcurementOperationsController::class, 'sendOrder'])->whereUuid('id')->name('procurement.orders.send');
        Route::post('goods-receipts/{id}/inspection', [ProcurementOperationsController::class, 'inspect'])->whereUuid('id')->name('procurement.receipts.inspection');
        Route::post('returns/{id}/credit-note', [ProcurementOperationsController::class, 'creditNote'])->whereUuid('id')->name('procurement.returns.credit-note');
        Route::post('three-way-match/{id}/approve-variance', [ProcurementOperationsController::class, 'approveVariance'])->whereUuid('id')->name('procurement.three-way-match.approve-variance');

        // 14. Landed costs
        Route::get('landed-costs', [LandedCostController::class, 'index'])->name('procurement.landed-costs.index');
        Route::post('landed-costs', [LandedCostController::class, 'store'])->name('procurement.landed-costs.store');
        Route::get('landed-costs/{id}', [LandedCostController::class, 'show'])->whereUuid('id')->name('procurement.landed-costs.show');
        Route::post('landed-costs/{id}/post', [LandedCostController::class, 'post'])->whereUuid('id')->name('procurement.landed-costs.post');
        Route::post('landed-costs/{id}/cancel', [LandedCostController::class, 'cancel'])->whereUuid('id')->name('procurement.landed-costs.cancel');

        // 15. Analytics
        Route::get('analytics/spend', [ProcurementAnalyticsController::class, 'spend'])->name('procurement.analytics.spend');
        Route::get('analytics/open-orders', [ProcurementAnalyticsController::class, 'openOrders'])->name('procurement.analytics.open-orders');
        Route::get('analytics/price-variance', [ProcurementAnalyticsController::class, 'priceVariance'])->name('procurement.analytics.price-variance');

        // 16. Purchase suggestions from reorder rules and MRP
        Route::get('purchase-suggestions', [PurchaseSuggestionController::class, 'index'])->name('procurement.suggestions.index');
        Route::post('purchase-suggestions/generate', [PurchaseSuggestionController::class, 'generate'])->name('procurement.suggestions.generate');
        Route::post('purchase-suggestions/convert', [PurchaseSuggestionController::class, 'convert'])->name('procurement.suggestions.convert');
        Route::post('purchase-suggestions/{id}/dismiss', [PurchaseSuggestionController::class, 'dismiss'])->whereUuid('id')->name('procurement.suggestions.dismiss');

        // 17. Supplier Invoice Approval
        Route::get('supplier-invoices/{id}/approval', [InvoiceApprovalController::class, 'show'])->whereUuid('id')->name('procurement.invoice-approval.show');
        Route::post('supplier-invoices/{id}/submit-approval', [InvoiceApprovalController::class, 'submit'])->whereUuid('id')->name('procurement.invoice-approval.submit');
    });

// The rules decide who can release a payment, so they belong to the finance roles.
Route::prefix('api/v1/invoice-approvals')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:ledger'])
    ->group(function (): void {
        Route::get('matrices', [InvoiceApprovalController::class, 'matrices'])->name('invoice-approvals.matrices.index');
        Route::post('matrices', [InvoiceApprovalController::class, 'storeMatrix'])->name('invoice-approvals.matrices.store');
        Route::delete('matrices/{id}', [InvoiceApprovalController::class, 'destroyMatrix'])->whereUuid('id')->name('invoice-approvals.matrices.destroy');
    });
