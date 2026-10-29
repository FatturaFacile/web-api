<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\GeneralLedger\Http\Controllers\ChartOfAccountController;
use Modules\GeneralLedger\Http\Controllers\FinancialStatementController;
use Modules\GeneralLedger\Http\Controllers\FiscalPeriodController;
use Modules\GeneralLedger\Http\Controllers\FiscalYearController;
use Modules\GeneralLedger\Http\Controllers\JournalEntryController;
use Modules\GeneralLedger\Http\Controllers\ReconciliationController;
use Modules\GeneralLedger\Http\Controllers\SubledgerController;
use Modules\GeneralLedger\Http\Controllers\TaxSettlementController;

Route::prefix('api/v1/general-ledger')
    ->middleware(['api'])
    ->group(function (): void {
        // Chart of Accounts
        Route::get('accounts-tree', [ChartOfAccountController::class, 'hierarchy']);
        Route::apiResource('accounts', ChartOfAccountController::class);

        // Fiscal Calendar
        Route::apiResource('fiscal-years', FiscalYearController::class);
        Route::post('fiscal-years/{id}/close', [FiscalYearController::class, 'closeYear']);

        Route::apiResource('fiscal-periods', FiscalPeriodController::class);
        Route::post('fiscal-periods/{id}/close', [FiscalPeriodController::class, 'closePeriod']);
        Route::post('fiscal-periods/{id}/reopen', [FiscalPeriodController::class, 'reopenPeriod']);

        // Double-Entry Journals
        Route::post('journals/validate-balance', [JournalEntryController::class, 'validateBalance']);
        Route::post('journals/{id}/post', [JournalEntryController::class, 'post']);
        Route::post('journals/{id}/reverse', [JournalEntryController::class, 'reverse']);
        Route::apiResource('journals', JournalEntryController::class);

        // Financial Statements
        Route::get('financial-statements/trial-balance', [FinancialStatementController::class, 'trialBalance']);
        Route::get('financial-statements/balance-sheet', [FinancialStatementController::class, 'balanceSheet']);
        Route::get('financial-statements/income-statement', [FinancialStatementController::class, 'incomeStatement']);
        Route::get('financial-statements/cash-flow', [FinancialStatementController::class, 'cashFlow']);

        // Subledgers & Reconciliations
        Route::apiResource('reconciliations', ReconciliationController::class);
        Route::get('subledgers/customers', [SubledgerController::class, 'customerLedger']);
        Route::get('subledgers/suppliers', [SubledgerController::class, 'supplierLedger']);

        // Tax Settlements (Liquidazione IVA)
        Route::get('tax-settlements/{id}', [TaxSettlementController::class, 'show']);
        Route::post('tax-settlements/calculate', [TaxSettlementController::class, 'calculate']);
        Route::post('tax-settlements/{id}/post', [TaxSettlementController::class, 'post']);
    });
