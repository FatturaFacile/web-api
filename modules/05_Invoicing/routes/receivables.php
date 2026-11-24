<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Invoicing\Http\Controllers\Receivables\CreditLimitController;
use Modules\Invoicing\Http\Controllers\Receivables\CustomerStatementController;
use Modules\Invoicing\Http\Controllers\Receivables\ReceivableItemController;
use Modules\Invoicing\Http\Controllers\Receivables\ReceivablesAnalyticsController;

// Accounts receivable and credit management (Mod 013): open items, ledger, reconciliation, credit limits and blocks,
// aging, DSO, risk, prudential provision and the customer statement.
Route::prefix('api/v1/invoicing/receivables')->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:invoicing'])->group(function (): void {
    Route::get('dashboard', [ReceivablesAnalyticsController::class, 'dashboard'])->name('invoicing.receivables.dashboard');
    Route::get('aging', [ReceivablesAnalyticsController::class, 'aging'])->name('invoicing.receivables.aging');
    Route::get('dso', [ReceivablesAnalyticsController::class, 'dso'])->name('invoicing.receivables.dso');
    Route::get('provision', [ReceivablesAnalyticsController::class, 'provision'])->name('invoicing.receivables.provision');
    Route::post('provision/post', [ReceivablesAnalyticsController::class, 'postProvision'])->name('invoicing.receivables.provision.post');
    Route::get('provision/postings', [ReceivablesAnalyticsController::class, 'provisionPostings'])->name('invoicing.receivables.provision.postings');
    Route::put('provision/rates', [ReceivablesAnalyticsController::class, 'saveRates'])->name('invoicing.receivables.provision.rates');

    Route::get('items', [ReceivableItemController::class, 'index'])->name('invoicing.receivables.items.index');
    Route::get('items/{id}', [ReceivableItemController::class, 'show'])->whereUuid('id')->name('invoicing.receivables.items.show');
    Route::post('items/{id}/dispute', [ReceivableItemController::class, 'dispute'])->whereUuid('id')->name('invoicing.receivables.items.dispute');
    Route::post('allocations', [ReceivableItemController::class, 'allocate'])->name('invoicing.receivables.allocations.store');
    Route::post('allocations/{id}/reverse', [ReceivableItemController::class, 'reverse'])->whereUuid('id')->name('invoicing.receivables.allocations.reverse');

    Route::get('credit-check', [CreditLimitController::class, 'check'])->name('invoicing.receivables.credit-check');
    Route::post('credit-limits', [CreditLimitController::class, 'store'])->name('invoicing.receivables.credit-limits.store');
    Route::put('credit-limits/{id}', [CreditLimitController::class, 'update'])->whereUuid('id')->name('invoicing.receivables.credit-limits.update');
    Route::delete('credit-limits/{id}', [CreditLimitController::class, 'destroy'])->whereUuid('id')->name('invoicing.receivables.credit-limits.destroy');
    Route::post('overrides/{id}/revoke', [CreditLimitController::class, 'revokeOverride'])->whereUuid('id')->name('invoicing.receivables.overrides.revoke');

    Route::prefix('customers/{customerId}')->whereUuid('customerId')->group(function (): void {
        Route::get('ledger', [ReceivableItemController::class, 'ledger'])->name('invoicing.receivables.customers.ledger');
        Route::get('unreconciled-payments', [ReceivableItemController::class, 'unreconciledPayments'])->name('invoicing.receivables.customers.unreconciled-payments');
        Route::get('credit', [CreditLimitController::class, 'status'])->name('invoicing.receivables.customers.credit');
        Route::get('risk', [CreditLimitController::class, 'risk'])->name('invoicing.receivables.customers.risk');
        Route::get('credit-limits', [CreditLimitController::class, 'index'])->name('invoicing.receivables.customers.credit-limits');
        Route::post('overrides', [CreditLimitController::class, 'grantOverride'])->name('invoicing.receivables.customers.overrides.store');
        Route::get('statement', [CustomerStatementController::class, 'show'])->name('invoicing.receivables.customers.statement');
        Route::get('statement/pdf', [CustomerStatementController::class, 'pdf'])->name('invoicing.receivables.customers.statement.pdf');
        Route::get('statement/xlsx', [CustomerStatementController::class, 'xlsx'])->name('invoicing.receivables.customers.statement.xlsx');
        Route::post('statement/send', [CustomerStatementController::class, 'send'])->name('invoicing.receivables.customers.statement.send');
    });
});
