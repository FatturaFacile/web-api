<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\AccountsPayable\Http\Controllers\AccountingPayablesController;
use Modules\AccountsPayable\Http\Controllers\OpenItemController;
use Modules\AccountsPayable\Http\Controllers\PayablesAgingController;
use Modules\AccountsPayable\Http\Controllers\PaymentRunController;
use Modules\AccountsPayable\Http\Controllers\SupplierTermController;

// accounts_payable: the accountants and the administrators keep the supplier ledger, prepare and execute the payment runs; the
// read-only accountant reads everything. Approving or rejecting a payment run is the job of the CFO (accounts_payable_approval:
// owners and administrators, or a member granted accounts_payable_approval.write).
Route::prefix('api/v1/accounts-payable')->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])->group(function (): void {
    // Simulations that save nothing: a POST that only needs read access.
    Route::middleware('module.access:accounts_payable,read')->group(function (): void {
        Route::post('supplier-terms/parse', [SupplierTermController::class, 'parse'])->name('accounts-payable.supplier-terms.parse');
        Route::post('payment-runs/preview', [PaymentRunController::class, 'preview'])->name('accounts-payable.payment-runs.preview');
    });

    Route::middleware('module.access:accounts_payable')->group(function (): void {
        Route::get('open-items', [OpenItemController::class, 'index'])->name('accounts-payable.open-items.index');
        Route::post('open-items', [OpenItemController::class, 'store'])->name('accounts-payable.open-items.store');
        Route::get('open-items/{id}', [OpenItemController::class, 'show'])->whereUuid('id')->name('accounts-payable.open-items.show');
        Route::get('open-items/{id}/history', [OpenItemController::class, 'history'])->whereUuid('id')->name('accounts-payable.open-items.history');
        Route::post('open-items/{id}/dispute', [OpenItemController::class, 'dispute'])->whereUuid('id')->name('accounts-payable.open-items.dispute');
        Route::post('open-items/{id}/block', [OpenItemController::class, 'block'])->whereUuid('id')->name('accounts-payable.open-items.block');
        Route::post('open-items/{id}/release', [OpenItemController::class, 'release'])->whereUuid('id')->name('accounts-payable.open-items.release');
        Route::put('open-items/{id}/skonto-terms', [OpenItemController::class, 'skontoTerms'])->whereUuid('id')->name('accounts-payable.open-items.skonto-terms');

        Route::get('supplier-terms', [SupplierTermController::class, 'index'])->name('accounts-payable.supplier-terms.index');
        Route::post('supplier-terms', [SupplierTermController::class, 'store'])->name('accounts-payable.supplier-terms.store');
        Route::put('supplier-terms/{id}', [SupplierTermController::class, 'update'])->whereUuid('id')->name('accounts-payable.supplier-terms.update');
        Route::delete('supplier-terms/{id}', [SupplierTermController::class, 'destroy'])->whereUuid('id')->name('accounts-payable.supplier-terms.destroy');

        Route::get('payment-runs', [PaymentRunController::class, 'index'])->name('accounts-payable.payment-runs.index');
        Route::post('payment-runs', [PaymentRunController::class, 'store'])->name('accounts-payable.payment-runs.store');
        Route::get('payment-runs/{id}', [PaymentRunController::class, 'show'])->whereUuid('id')->name('accounts-payable.payment-runs.show');
        Route::delete('payment-runs/{id}/items/{itemId}', [PaymentRunController::class, 'removeItem'])->whereUuid(['id', 'itemId'])->name('accounts-payable.payment-runs.items.destroy');
        Route::post('payment-runs/{id}/submit', [PaymentRunController::class, 'submit'])->whereUuid('id')->name('accounts-payable.payment-runs.submit');
        Route::post('payment-runs/{id}/recall', [PaymentRunController::class, 'recall'])->whereUuid('id')->name('accounts-payable.payment-runs.recall');
        Route::post('payment-runs/{id}/execute', [PaymentRunController::class, 'execute'])->whereUuid('id')->name('accounts-payable.payment-runs.execute');
        Route::post('payment-runs/{id}/cancel', [PaymentRunController::class, 'cancel'])->whereUuid('id')->name('accounts-payable.payment-runs.cancel');

        Route::get('aging', [PayablesAgingController::class, 'index'])->name('accounts-payable.aging');
        Route::get('aging/export/xlsx', [PayablesAgingController::class, 'xlsx'])->name('accounts-payable.aging.xlsx');
        Route::get('aging/export/pdf', [PayablesAgingController::class, 'pdf'])->name('accounts-payable.aging.pdf');
    });

    Route::middleware('module.access:accounts_payable_approval,write')->group(function (): void {
        Route::post('payment-runs/{id}/approve', [PaymentRunController::class, 'approve'])->whereUuid('id')->name('accounts-payable.payment-runs.approve');
        Route::post('payment-runs/{id}/reject', [PaymentRunController::class, 'reject'])->whereUuid('id')->name('accounts-payable.payment-runs.reject');
    });
});

// The same services in the contract of the web client (items, batches, aging): a thin layer, the rules are the ones above. The
// accounting prefix belongs to the ledger module (read access to the books), the stricter accounts_payable gates below decide the rest.
Route::prefix('api/v1/accounting/payables')->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:ledger,read'])->group(function (): void {
    Route::middleware('module.access:accounts_payable,read')->group(function (): void {
        Route::post('batches/preview', [AccountingPayablesController::class, 'preview'])->name('accounts-payable.payables.batches.preview');
    });

    Route::middleware('module.access:accounts_payable')->group(function (): void {
        Route::get('items', [AccountingPayablesController::class, 'items'])->name('accounts-payable.payables.items.index');
        Route::get('items/summary', [AccountingPayablesController::class, 'summary'])->name('accounts-payable.payables.items.summary');
        Route::get('items/{id}', [AccountingPayablesController::class, 'item'])->whereUuid('id')->name('accounts-payable.payables.items.show');
        Route::post('items/{id}/block', [AccountingPayablesController::class, 'block'])->whereUuid('id')->name('accounts-payable.payables.items.block');
        Route::post('items/{id}/release', [AccountingPayablesController::class, 'release'])->whereUuid('id')->name('accounts-payable.payables.items.release');

        Route::get('batches', [AccountingPayablesController::class, 'batches'])->name('accounts-payable.payables.batches.index');
        Route::post('batches', [AccountingPayablesController::class, 'createBatch'])->name('accounts-payable.payables.batches.store');
        Route::get('batches/{id}', [AccountingPayablesController::class, 'batch'])->whereUuid('id')->name('accounts-payable.payables.batches.show');
        Route::post('batches/{id}/submit', [AccountingPayablesController::class, 'submit'])->whereUuid('id')->name('accounts-payable.payables.batches.submit');
        Route::post('batches/{id}/execute', [AccountingPayablesController::class, 'execute'])->whereUuid('id')->name('accounts-payable.payables.batches.execute');
        Route::post('batches/{id}/cancel', [AccountingPayablesController::class, 'cancel'])->whereUuid('id')->name('accounts-payable.payables.batches.cancel');

        Route::get('aging', [AccountingPayablesController::class, 'agingReport'])->name('accounts-payable.payables.aging');
        Route::get('aging/export/{format}', [AccountingPayablesController::class, 'exportAging'])->whereIn('format', ['xlsx', 'pdf'])->name('accounts-payable.payables.aging.export');
    });

    Route::middleware('module.access:accounts_payable_approval,write')->group(function (): void {
        Route::post('batches/{id}/approve', [AccountingPayablesController::class, 'approve'])->whereUuid('id')->name('accounts-payable.payables.batches.approve');
        Route::post('batches/{id}/reject', [AccountingPayablesController::class, 'reject'])->whereUuid('id')->name('accounts-payable.payables.batches.reject');
    });
});
