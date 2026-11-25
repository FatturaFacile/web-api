<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ExpenseManagement\Http\Controllers\CardController;
use Modules\ExpenseManagement\Http\Controllers\LineController;
use Modules\ExpenseManagement\Http\Controllers\MileageController;
use Modules\ExpenseManagement\Http\Controllers\PerDiemController;
use Modules\ExpenseManagement\Http\Controllers\ReceiptController;
use Modules\ExpenseManagement\Http\Controllers\ReferenceController;
use Modules\ExpenseManagement\Http\Controllers\ReportController;
use Modules\ExpenseManagement\Http\Controllers\WorkflowController;

/*
|--------------------------------------------------------------------------
| Expense and travel management (Mod 075 / Issue #166)
|--------------------------------------------------------------------------
| Expense reports with receipts (OCR, SHA-256 duplicate detection), mileage claims (ACI / TCS), per-diem allowances,
| corporate card reconciliation, approval and payout (pain.001, payroll, ledger, project rebilling).
*/
Route::prefix('api/v1/expense-management')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:expense_management'])
    ->group(function (): void {
        Route::get('lookups', [ReferenceController::class, 'lookups'])->name('expense-management.lookups');
        Route::get('aci-rates', [ReferenceController::class, 'rates'])->name('expense-management.aci-rates');
        Route::get('policies', [ReferenceController::class, 'policies'])->name('expense-management.policies');
        Route::get('balance', [ReferenceController::class, 'balance'])->name('expense-management.balance');
        Route::get('policy-violations', [ReferenceController::class, 'violations'])->name('expense-management.policy-violations');

        Route::get('reports', [ReportController::class, 'index'])->name('expense-management.reports.index');
        Route::post('reports', [ReportController::class, 'store'])->name('expense-management.reports.store');
        Route::get('reports/{id}', [ReportController::class, 'show'])->whereUuid('id')->name('expense-management.reports.show');
        Route::put('reports/{id}', [ReportController::class, 'update'])->whereUuid('id')->name('expense-management.reports.update');
        Route::delete('reports/{id}', [ReportController::class, 'destroy'])->whereUuid('id')->name('expense-management.reports.destroy');

        Route::post('reports/{id}/lines', [LineController::class, 'store'])->whereUuid('id')->name('expense-management.lines.store');
        Route::put('reports/{id}/lines/{lineId}', [LineController::class, 'update'])->whereUuid(['id', 'lineId'])->name('expense-management.lines.update');
        Route::delete('reports/{id}/lines/{lineId}', [LineController::class, 'destroy'])->whereUuid(['id', 'lineId'])->name('expense-management.lines.destroy');

        Route::post('mileage-claims/quote', [MileageController::class, 'quote'])->name('expense-management.mileage.quote');
        Route::post('reports/{id}/mileage-claims', [MileageController::class, 'store'])->whereUuid('id')->name('expense-management.mileage.store');
        Route::delete('reports/{id}/mileage-claims/{claimId}', [MileageController::class, 'destroy'])->whereUuid(['id', 'claimId'])->name('expense-management.mileage.destroy');

        Route::post('per-diems/preview', [PerDiemController::class, 'preview'])->name('expense-management.per-diems.preview');
        Route::post('reports/{id}/per-diems', [PerDiemController::class, 'store'])->whereUuid('id')->name('expense-management.per-diems.store');
        Route::delete('reports/{id}/per-diems/{perDiemId}', [PerDiemController::class, 'destroy'])->whereUuid(['id', 'perDiemId'])->name('expense-management.per-diems.destroy');

        Route::post('reports/{id}/submit', [WorkflowController::class, 'submit'])->whereUuid('id')->name('expense-management.reports.submit');
        Route::post('reports/{id}/start-review', [WorkflowController::class, 'startReview'])->whereUuid('id')->name('expense-management.reports.start-review');
        Route::post('reports/{id}/approve', [WorkflowController::class, 'approve'])->whereUuid('id')->name('expense-management.reports.approve');
        Route::post('reports/{id}/reject', [WorkflowController::class, 'reject'])->whereUuid('id')->name('expense-management.reports.reject');
        Route::post('reports/{id}/cancel', [WorkflowController::class, 'cancel'])->whereUuid('id')->name('expense-management.reports.cancel');
        Route::post('reports/{id}/export-payout', [WorkflowController::class, 'exportPayout'])->whereUuid('id')->name('expense-management.reports.export-payout');
        Route::post('reports/{id}/confirm-reimbursement', [WorkflowController::class, 'confirmReimbursement'])->whereUuid('id')->name('expense-management.reports.confirm-reimbursement');
        Route::get('reports/{id}/payouts/{payoutId}/file', [WorkflowController::class, 'downloadPayout'])->whereUuid(['id', 'payoutId'])->name('expense-management.payouts.file');

        Route::post('receipts/scan', [ReceiptController::class, 'scan'])->name('expense-management.receipts.scan');
        Route::get('receipts/{id}/file', [ReceiptController::class, 'file'])->whereUuid('id')->name('expense-management.receipts.file');

        Route::get('card-accounts', [CardController::class, 'accounts'])->name('expense-management.card-accounts');
        Route::get('card-transactions', [CardController::class, 'transactions'])->name('expense-management.card-transactions.index');
        Route::post('card-transactions/import', [CardController::class, 'import'])->name('expense-management.card-transactions.import');
        Route::post('card-transactions/{id}/reconcile', [CardController::class, 'reconcile'])->whereUuid('id')->name('expense-management.card-transactions.reconcile');
    });
