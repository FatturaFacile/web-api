<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\GeneralLedger\Http\Controllers\ChartOfAccountController;
use Modules\GeneralLedger\Http\Controllers\EnasarcoController;
use Modules\GeneralLedger\Http\Controllers\FinancialStatementController;
use Modules\GeneralLedger\Http\Controllers\FiscalPeriodController;
use Modules\GeneralLedger\Http\Controllers\FiscalYearController;
use Modules\GeneralLedger\Http\Controllers\JournalEntryController;
use Modules\GeneralLedger\Http\Controllers\PayrollLedgerController;
use Modules\GeneralLedger\Http\Controllers\ReconciliationController;
use Modules\GeneralLedger\Http\Controllers\SubledgerController;
use Modules\GeneralLedger\Http\Controllers\TaxSettlementController;
use Modules\GeneralLedger\Http\Controllers\VatRegisterController;
use Modules\GeneralLedger\Http\Controllers\VatTaxSettingController;
use Modules\GeneralLedger\Http\Controllers\WithholdingSettlementController;

Route::prefix('api/v1/general-ledger')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:ledger'])
    ->group(function (): void {
        // Chart of Accounts
        Route::get('accounts-tree', [ChartOfAccountController::class, 'hierarchy']);
        Route::apiResource('accounts', ChartOfAccountController::class);

        // Fiscal Calendar
        Route::apiResource('fiscal-years', FiscalYearController::class)->except(['update', 'destroy']);
        Route::post('fiscal-years/{id}/close', [FiscalYearController::class, 'closeYear']);

        Route::apiResource('fiscal-periods', FiscalPeriodController::class)->except(['update', 'destroy']);
        Route::post('fiscal-periods/{id}/close', [FiscalPeriodController::class, 'closePeriod']);
        Route::post('fiscal-periods/{id}/reopen', [FiscalPeriodController::class, 'reopenPeriod']);

        // Double-Entry Journals
        Route::post('journals/validate-balance', [JournalEntryController::class, 'validateBalance']);
        Route::post('journals/{id}/post', [JournalEntryController::class, 'post']);
        Route::post('journals/{id}/reverse', [JournalEntryController::class, 'reverse']);
        Route::apiResource('journals', JournalEntryController::class)->except(['update', 'destroy']);

        // Financial Statements
        Route::get('financial-statements/trial-balance', [FinancialStatementController::class, 'trialBalance']);
        Route::get('financial-statements/balance-sheet', [FinancialStatementController::class, 'balanceSheet']);
        Route::get('financial-statements/income-statement', [FinancialStatementController::class, 'incomeStatement']);
        Route::get('financial-statements/cash-flow', [FinancialStatementController::class, 'cashFlow']);

        // Subledgers & Reconciliations
        Route::apiResource('reconciliations', ReconciliationController::class)->except(['update', 'destroy']);
        Route::get('subledgers/customers', [SubledgerController::class, 'customerLedger']);
        Route::get('subledgers/suppliers', [SubledgerController::class, 'supplierLedger']);

        // Tax Settlements (Liquidazione IVA)
        Route::get('tax-settlements', [TaxSettlementController::class, 'index']);
        Route::post('tax-settlements/calculate', [TaxSettlementController::class, 'calculate']);
        Route::get('tax-settlements/{id}', [TaxSettlementController::class, 'show'])->whereUuid('id');
        Route::post('tax-settlements/{id}/post', [TaxSettlementController::class, 'post'])->whereUuid('id');
        Route::post('tax-settlements/{id}/pay', [TaxSettlementController::class, 'pay'])->whereUuid('id');
        Route::get('tax-settlements/{id}/lipe', [TaxSettlementController::class, 'lipe'])->whereUuid('id');
        Route::get('tax-settlements/{id}/f24', [TaxSettlementController::class, 'f24'])->whereUuid('id');
        Route::get('tax-settlements/{id}/statement', [TaxSettlementController::class, 'statement'])->whereUuid('id');

        // Withholding Settlements (Liquidazione ritenute)
        Route::get('withholding-settlements', [WithholdingSettlementController::class, 'index']);
        Route::post('withholding-settlements/calculate', [WithholdingSettlementController::class, 'calculate']);
        Route::get('withholding-settlements/certification-export', [WithholdingSettlementController::class, 'certificationExport']);
        Route::get('withholding-settlements/{id}', [WithholdingSettlementController::class, 'show'])->whereUuid('id');
        Route::get('withholding-settlements/{id}/f24', [WithholdingSettlementController::class, 'f24'])->whereUuid('id');
        Route::post('withholding-settlements/{id}/pay', [WithholdingSettlementController::class, 'pay'])->whereUuid('id');

        // Enasarco
        Route::get('enasarco/parameters', [EnasarcoController::class, 'parameters']);
        Route::put('enasarco/parameters', [EnasarcoController::class, 'saveParameter']);
        Route::get('enasarco/summary', [EnasarcoController::class, 'summary']);

        // VAT Settings and Registers
        Route::get('payroll-settings', [PayrollLedgerController::class, 'showSettings']);
        Route::put('payroll-settings', [PayrollLedgerController::class, 'updateSettings']);
        Route::get('payroll-postings', [PayrollLedgerController::class, 'postings']);
        Route::post('payroll-postings/{runId}/post', [PayrollLedgerController::class, 'post'])->whereUuid('runId');
        Route::get('tax-settings', [VatTaxSettingController::class, 'show']);
        Route::put('tax-settings', [VatTaxSettingController::class, 'update']);
        Route::get('vat-registers/{type}', [VatRegisterController::class, 'show'])->whereIn('type', ['sales', 'purchases']);
    });
