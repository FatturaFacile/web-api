<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\GeneralLedger\Http\Controllers\AccountingTemplateController;
use Modules\GeneralLedger\Http\Controllers\AccrualController;
use Modules\GeneralLedger\Http\Controllers\ChartOfAccountController;
use Modules\GeneralLedger\Http\Controllers\ChartTemplateController;
use Modules\GeneralLedger\Http\Controllers\CountryVatReturnController;
use Modules\GeneralLedger\Http\Controllers\DimensionController;
use Modules\GeneralLedger\Http\Controllers\EnasarcoController;
use Modules\GeneralLedger\Http\Controllers\FinancialAnalysisController;
use Modules\GeneralLedger\Http\Controllers\FinancialStatementController;
use Modules\GeneralLedger\Http\Controllers\FiscalPeriodController;
use Modules\GeneralLedger\Http\Controllers\FiscalYearController;
use Modules\GeneralLedger\Http\Controllers\JournalBookController;
use Modules\GeneralLedger\Http\Controllers\JournalEntryController;
use Modules\GeneralLedger\Http\Controllers\JournalLetteringController;
use Modules\GeneralLedger\Http\Controllers\LedgerBudgetController;
use Modules\GeneralLedger\Http\Controllers\LedgerReportController;
use Modules\GeneralLedger\Http\Controllers\LedgerSettingsController;
use Modules\GeneralLedger\Http\Controllers\LocalizationPackController;
use Modules\GeneralLedger\Http\Controllers\OpenItemFxRevaluationController;
use Modules\GeneralLedger\Http\Controllers\PayrollLedgerController;
use Modules\GeneralLedger\Http\Controllers\PeriodCloseController;
use Modules\GeneralLedger\Http\Controllers\ReconciliationController;
use Modules\GeneralLedger\Http\Controllers\RecurringJournalController;
use Modules\GeneralLedger\Http\Controllers\StatementSchemeController;
use Modules\GeneralLedger\Http\Controllers\SubledgerController;
use Modules\GeneralLedger\Http\Controllers\TaxSettlementController;
use Modules\GeneralLedger\Http\Controllers\Us1099Controller;
use Modules\GeneralLedger\Http\Controllers\UsTaxController;
use Modules\GeneralLedger\Http\Controllers\UsTaxExemptionCertificateController;
use Modules\GeneralLedger\Http\Controllers\VatPeriodSettlementController;
use Modules\GeneralLedger\Http\Controllers\VatRegisterController;
use Modules\GeneralLedger\Http\Controllers\VatTaxSettingController;
use Modules\GeneralLedger\Http\Controllers\WithholdingSettlementController;
use Modules\GeneralLedger\Http\Controllers\YearEndClosingWorkflowController;

Route::prefix('api/v1/general-ledger')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:ledger'])
    ->group(function (): void {
        // Chart of Accounts
        Route::get('accounts-tree', [ChartOfAccountController::class, 'hierarchy']);
        Route::get('account-templates', [ChartTemplateController::class, 'index']);
        Route::post('account-templates/{template}/import', [ChartTemplateController::class, 'import']);
        Route::apiResource('accounts', ChartOfAccountController::class);

        // Fiscal Calendar
        Route::apiResource('fiscal-years', FiscalYearController::class)->except(['update', 'destroy']);
        Route::post('fiscal-years/{id}/close', [FiscalYearController::class, 'closeYear']);
        Route::post('fiscal-years/{id}/reopen', [FiscalYearController::class, 'reopenYear'])->whereUuid('id');
        Route::post('fiscal-years/{id}/approve-agm', [FiscalYearController::class, 'approveAgm'])->whereUuid('id');
        Route::get('fiscal-years/{id}/closing-workflow', [YearEndClosingWorkflowController::class, 'show'])->whereUuid('id');
        Route::post('fiscal-years/{id}/closing-workflow/stages/{stage}', [YearEndClosingWorkflowController::class, 'run'])->whereUuid('id')->whereNumber('stage');
        Route::post('fiscal-years/{id}/carry-forward', [FinancialAnalysisController::class, 'carryForward'])->whereUuid('id');

        Route::apiResource('fiscal-periods', FiscalPeriodController::class)->except(['update', 'destroy']);
        Route::post('fiscal-periods/{id}/close', [FiscalPeriodController::class, 'closePeriod']);
        Route::post('fiscal-periods/{id}/reopen', [FiscalPeriodController::class, 'reopenPeriod']);
        Route::get('fiscal-periods/{id}/close-checklist', [PeriodCloseController::class, 'show'])->whereUuid('id');
        Route::post('fiscal-periods/{id}/close-tasks', [PeriodCloseController::class, 'storeTask'])->whereUuid('id');
        Route::post('fiscal-periods/{id}/close-tasks/{taskId}/status', [PeriodCloseController::class, 'updateTaskStatus'])->whereUuid(['id', 'taskId']);

        // Parametric journal templates (causali contabili)
        Route::get('accounting-templates', [AccountingTemplateController::class, 'index']);
        Route::post('accounting-templates', [AccountingTemplateController::class, 'store']);
        Route::get('accounting-templates/{id}', [AccountingTemplateController::class, 'show'])->whereUuid('id');
        Route::put('accounting-templates/{id}', [AccountingTemplateController::class, 'update'])->whereUuid('id');
        Route::delete('accounting-templates/{id}', [AccountingTemplateController::class, 'destroy'])->whereUuid('id');
        Route::post('accounting-templates/{id}/preview', [AccountingTemplateController::class, 'preview'])->whereUuid('id');
        Route::post('accounting-templates/{id}/post', [AccountingTemplateController::class, 'post'])->whereUuid('id');

        // Double-Entry Journals
        Route::post('journals/validate-balance', [JournalEntryController::class, 'validateBalance']);
        Route::post('journals/{id}/post', [JournalEntryController::class, 'post']);
        Route::post('journals/{id}/reverse', [JournalEntryController::class, 'reverse']);
        Route::post('journals/{id}/submit', [JournalEntryController::class, 'submit'])->whereUuid('id');
        Route::post('journals/{id}/approve', [JournalEntryController::class, 'approve'])->whereUuid('id');
        Route::post('journals/{id}/reject', [JournalEntryController::class, 'reject'])->whereUuid('id');
        Route::apiResource('journals', JournalEntryController::class)->except(['update', 'destroy']);
        Route::get('journal-book/export', [JournalBookController::class, 'export'])->name('general-ledger.journal-book.export');

        // Ledger settings: default posting accounts and four-eyes policy
        Route::get('settings', [LedgerSettingsController::class, 'show']);
        Route::put('settings', [LedgerSettingsController::class, 'update']);

        // Recurring journals, scheduled work, accruals and deferrals, FX revaluation of open items
        Route::get('recurring-journals', [RecurringJournalController::class, 'index']);
        Route::post('recurring-journals', [RecurringJournalController::class, 'store']);
        Route::get('recurring-journals/{id}', [RecurringJournalController::class, 'show'])->whereUuid('id');
        Route::post('recurring-journals/{id}/status', [RecurringJournalController::class, 'changeStatus'])->whereUuid('id');
        Route::post('recurring-journals/{id}/run', [RecurringJournalController::class, 'run'])->whereUuid('id');
        Route::post('scheduled-journals/run', [RecurringJournalController::class, 'runScheduled']);
        Route::get('accruals', [AccrualController::class, 'index']);
        Route::post('accruals', [AccrualController::class, 'store']);
        Route::get('accruals/{id}', [AccrualController::class, 'show'])->whereUuid('id');
        Route::get('fx-revaluations', [OpenItemFxRevaluationController::class, 'index']);
        Route::post('fx-revaluations', [OpenItemFxRevaluationController::class, 'store']);

        // Analysis dimensions
        Route::get('cost-centers', [DimensionController::class, 'indexCostCenters']);
        Route::post('cost-centers', [DimensionController::class, 'storeCostCenter']);
        Route::put('cost-centers/{id}', [DimensionController::class, 'updateCostCenter'])->whereUuid('id');
        Route::get('profit-centers', [DimensionController::class, 'indexProfitCenters']);
        Route::post('profit-centers', [DimensionController::class, 'storeProfitCenter']);
        Route::put('profit-centers/{id}', [DimensionController::class, 'updateProfitCenter'])->whereUuid('id');

        // Reports from posted entries and audit trail
        Route::get('reports/trial-balance', [LedgerReportController::class, 'trialBalance']);
        Route::get('reports/account-ledger', [LedgerReportController::class, 'accountLedger']);
        Route::get('reports/balance-sheet', [LedgerReportController::class, 'balanceSheet']);
        Route::get('reports/income-statement', [LedgerReportController::class, 'incomeStatement']);
        Route::get('reports/dimensions', [LedgerReportController::class, 'dimensions']);
        Route::get('audit-trail/export', [LedgerReportController::class, 'auditTrail']);

        // Financial Statements
        Route::get('financial-statements/trial-balance', [FinancialStatementController::class, 'trialBalance']);
        Route::get('financial-statements/balance-sheet', [FinancialStatementController::class, 'balanceSheet']);
        Route::get('financial-statements/income-statement', [FinancialStatementController::class, 'incomeStatement']);
        Route::get('financial-statements/cash-flow', [FinancialStatementController::class, 'cashFlow']);
        Route::get('financial-statements/comparative', [FinancialAnalysisController::class, 'comparative']);
        Route::get('financial-statements/reclassified', [FinancialAnalysisController::class, 'reclassified']);
        Route::get('financial-statements/cash-flow-statement', [FinancialAnalysisController::class, 'cashFlow']);
        Route::get('financial-statements/ratios', [FinancialAnalysisController::class, 'ratios']);
        Route::get('financial-statements/swiss-anhang/{fiscalYearId}', [FinancialStatementController::class, 'swissAnhang'])->whereUuid('fiscalYearId');
        Route::put('financial-statements/swiss-anhang/{fiscalYearId}', [FinancialStatementController::class, 'updateSwissAnhang'])->whereUuid('fiscalYearId');
        Route::get('statement-schemes', [StatementSchemeController::class, 'index']);
        Route::post('statement-schemes', [StatementSchemeController::class, 'store']);
        Route::delete('statement-schemes/{id}', [StatementSchemeController::class, 'destroy'])->whereUuid('id');

        // Company Budgets (project budgets are in modules/15_Projects)
        Route::get('budgets', [LedgerBudgetController::class, 'index']);
        Route::post('budgets', [LedgerBudgetController::class, 'store']);
        Route::get('budgets/{id}', [LedgerBudgetController::class, 'show'])->whereUuid('id');
        Route::put('budgets/{id}/lines', [LedgerBudgetController::class, 'setLines'])->whereUuid('id');
        Route::post('budgets/{id}/new-version', [LedgerBudgetController::class, 'newVersion'])->whereUuid('id');
        Route::post('budgets/{id}/approve', [LedgerBudgetController::class, 'approve'])->whereUuid('id');
        Route::get('budgets/{id}/variance', [LedgerBudgetController::class, 'variance'])->whereUuid('id');
        Route::get('budgets/{id}/rolling-forecast', [LedgerBudgetController::class, 'rollingForecast'])->whereUuid('id');

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
        Route::get('tax-settlements/{id}/swiss-xml', [TaxSettlementController::class, 'swissXml'])->whereUuid('id');
        Route::get('vat-annual-reconciliation', [TaxSettlementController::class, 'annualReconciliation']);

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
        Route::get('enasarco/principal-statements', [EnasarcoController::class, 'principalStatements']);
        Route::post('enasarco/principal-statements', [EnasarcoController::class, 'generatePrincipalStatement']);

        // VAT Settings and Registers
        Route::get('payroll-settings', [PayrollLedgerController::class, 'showSettings']);
        Route::put('payroll-settings', [PayrollLedgerController::class, 'updateSettings']);
        Route::get('payroll-postings', [PayrollLedgerController::class, 'postings']);
        Route::post('payroll-postings/{runId}/post', [PayrollLedgerController::class, 'post'])->whereUuid('runId');
        Route::get('tax-settings', [VatTaxSettingController::class, 'show']);
        Route::put('tax-settings', [VatTaxSettingController::class, 'update']);

        // Localization packs: France (FEC, VAT codes), Spain (SII VAT books, VAT codes), USA (sales tax, 1099)
        Route::get('vat-code-packs', [LocalizationPackController::class, 'vatCodePacks']);
        Route::post('vat-code-packs/{country}/import', [LocalizationPackController::class, 'importVatCodePack'])->whereIn('country', ['FR', 'ES', 'DE', 'fr', 'es', 'de']);
        Route::get('fec/export', [LocalizationPackController::class, 'fec']);
        Route::get('sii/{type}/export', [LocalizationPackController::class, 'sii'])->whereIn('type', ['sales', 'purchases']);
        Route::get('us-tax/jurisdictions', [UsTaxController::class, 'jurisdictions']);
        Route::post('us-tax/jurisdictions', [UsTaxController::class, 'storeJurisdiction']);
        Route::put('us-tax/jurisdictions/{id}', [UsTaxController::class, 'updateJurisdiction'])->whereUuid('id');
        Route::delete('us-tax/jurisdictions/{id}', [UsTaxController::class, 'destroyJurisdiction'])->whereUuid('id');
        Route::post('us-tax/jurisdictions/{id}/rates', [UsTaxController::class, 'storeRate'])->whereUuid('id');
        Route::put('us-tax/rates/{id}', [UsTaxController::class, 'updateRate'])->whereUuid('id');
        Route::delete('us-tax/rates/{id}', [UsTaxController::class, 'destroyRate'])->whereUuid('id');
        Route::get('us-tax/nexus', [UsTaxController::class, 'nexus']);
        Route::post('us-tax/nexus', [UsTaxController::class, 'storeNexus']);
        Route::put('us-tax/nexus/{id}', [UsTaxController::class, 'updateNexus'])->whereUuid('id');
        Route::delete('us-tax/nexus/{id}', [UsTaxController::class, 'destroyNexus'])->whereUuid('id');
        Route::post('us-tax/preload', [UsTaxController::class, 'preload']);
        Route::get('us-tax/lookup', [UsTaxController::class, 'lookup']);
        Route::post('us-tax/calculate', [UsTaxController::class, 'calculate']);
        Route::get('us-tax/exemption-certificates', [UsTaxExemptionCertificateController::class, 'index']);
        Route::post('us-tax/exemption-certificates', [UsTaxExemptionCertificateController::class, 'store']);
        Route::put('us-tax/exemption-certificates/{id}', [UsTaxExemptionCertificateController::class, 'update'])->whereUuid('id');
        Route::delete('us-tax/exemption-certificates/{id}', [UsTaxExemptionCertificateController::class, 'destroy'])->whereUuid('id');
        Route::get('us-1099/recipients', [Us1099Controller::class, 'recipients']);
        Route::post('us-1099/recipients', [Us1099Controller::class, 'storeRecipient']);
        Route::put('us-1099/recipients/{id}', [Us1099Controller::class, 'updateRecipient'])->whereUuid('id');
        Route::delete('us-1099/recipients/{id}', [Us1099Controller::class, 'destroyRecipient'])->whereUuid('id');
        Route::get('us-1099/payments', [Us1099Controller::class, 'payments']);
        Route::post('us-1099/payments', [Us1099Controller::class, 'storePayment']);
        Route::put('us-1099/payments/{id}', [Us1099Controller::class, 'updatePayment'])->whereUuid('id');
        Route::delete('us-1099/payments/{id}', [Us1099Controller::class, 'destroyPayment'])->whereUuid('id');
        Route::get('us-1099/supplier-payments', [Us1099Controller::class, 'supplierPayments']);
        Route::get('us-1099/summary', [Us1099Controller::class, 'summary']);
        Route::get('fr/ca3', [CountryVatReturnController::class, 'ca3']);
        Route::get('fr/ca3/export', [CountryVatReturnController::class, 'ca3Export']);
        Route::get('es/modelo-303', [CountryVatReturnController::class, 'modelo303']);
        Route::get('es/modelo-303/export', [CountryVatReturnController::class, 'modelo303Export']);
        Route::get('es/modelo-390', [CountryVatReturnController::class, 'modelo390']);
        Route::get('de/ustva', [CountryVatReturnController::class, 'ustva']);
        Route::get('de/ustva/export', [CountryVatReturnController::class, 'ustvaExport']);
        Route::get('de/datev/export', [CountryVatReturnController::class, 'datev']);
        Route::get('letterings', [JournalLetteringController::class, 'index']);
        Route::post('letterings', [JournalLetteringController::class, 'store']);
        Route::delete('letterings/{code}', [JournalLetteringController::class, 'destroy'])->where('code', '[A-Z]{2,}');
        Route::get('vat-registers/{type}', [VatRegisterController::class, 'show'])->whereIn('type', ['sales', 'purchases']);
    });

Route::prefix('api/v1/vat/settlements')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:ledger'])
    ->group(function (): void {
        Route::get('/', [VatPeriodSettlementController::class, 'index'])->name('vat.settlements.index');
        Route::post('preview', [VatPeriodSettlementController::class, 'preview'])->name('vat.settlements.preview');
        Route::post('/', [VatPeriodSettlementController::class, 'store'])->name('vat.settlements.store');
        Route::get('{settlement}', [VatPeriodSettlementController::class, 'show'])->whereUuid('settlement')->name('vat.settlements.show');
        Route::get('{settlement}/export-xml', [VatPeriodSettlementController::class, 'exportXml'])->whereUuid('settlement')->name('vat.settlements.export-xml');
        Route::get('{settlement}/export-pdf', [VatPeriodSettlementController::class, 'exportPdf'])->whereUuid('settlement')->name('vat.settlements.export-pdf');
    });
