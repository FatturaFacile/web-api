<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Treasury\Http\Controllers\BankAccountController;
use Modules\Treasury\Http\Controllers\BankReconciliationController;
use Modules\Treasury\Http\Controllers\CashForecastController;
use Modules\Treasury\Http\Controllers\CashPositionController;
use Modules\Treasury\Http\Controllers\CashRegisterController;
use Modules\Treasury\Http\Controllers\F24DelegationController;
use Modules\Treasury\Http\Controllers\FxRiskController;
use Modules\Treasury\Http\Controllers\LiquidityController;
use Modules\Treasury\Http\Controllers\LiquidityForecastController;
use Modules\Treasury\Http\Controllers\OpenBankingController;
use Modules\Treasury\Http\Controllers\OpenBankingSyncController;
use Modules\Treasury\Http\Controllers\PagoPaNoticeController;
use Modules\Treasury\Http\Controllers\PaymentBatchController;
use Modules\Treasury\Http\Controllers\PaymentFlowController;
use Modules\Treasury\Http\Controllers\PaymentProposalController;
use Modules\Treasury\Http\Controllers\ReceivableCollectionController;
use Modules\Treasury\Http\Controllers\RibaController;
use Modules\Treasury\Http\Controllers\RibaPortfolioController;
use Modules\Treasury\Http\Controllers\SmartReconciliationController;
use Modules\Treasury\Http\Controllers\StatementImportController;
use Modules\Treasury\Http\Controllers\TreasuryBalanceController;
use Modules\Treasury\Http\Controllers\TreasuryKpiController;
use Modules\Treasury\Http\Controllers\TreasuryMovementController;

Route::prefix('api/v1/treasury')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:treasury'])
    ->group(function (): void {
        // Bank Accounts Management, balances per date, fees and interest
        Route::get('balances', [TreasuryBalanceController::class, 'balances'])->name('treasury.balances');
        Route::get('bank-accounts/{id}/balances', [TreasuryBalanceController::class, 'history'])->whereUuid('id')->name('treasury.bank-accounts.balances');
        Route::post('bank-accounts/{id}/charges', [TreasuryMovementController::class, 'charge'])->whereUuid('id')->name('treasury.bank-accounts.charges');
        Route::apiResource('bank-accounts', BankAccountController::class)->middlewareFor('show', 'audit.access:BankAccount,VIEWED,,bank_account');

        // Internal transfers and treasury bank movements
        Route::get('movements', [TreasuryMovementController::class, 'index'])->middleware('audit.access:BankTransaction,VIEWED')->name('treasury.movements.index');
        Route::post('transfers', [TreasuryMovementController::class, 'transfer'])->name('treasury.transfers.store');

        // Cash registers (prima nota cassa) and daily closing
        Route::get('cash-registers', [CashRegisterController::class, 'index'])->name('treasury.cash-registers.index');
        Route::post('cash-registers', [CashRegisterController::class, 'store'])->name('treasury.cash-registers.store');
        Route::get('cash-registers/{id}', [CashRegisterController::class, 'show'])->whereUuid('id')->name('treasury.cash-registers.show');
        Route::get('cash-registers/{id}/movements', [CashRegisterController::class, 'movements'])->whereUuid('id')->name('treasury.cash-registers.movements.index');
        Route::post('cash-registers/{id}/movements', [CashRegisterController::class, 'storeMovement'])->whereUuid('id')->name('treasury.cash-registers.movements.store');
        Route::post('cash-registers/{id}/close', [CashRegisterController::class, 'close'])->whereUuid('id')->name('treasury.cash-registers.close');
        Route::get('cash-registers/{id}/closings', [CashRegisterController::class, 'closings'])->whereUuid('id')->name('treasury.cash-registers.closings');

        // Supplier payment proposals: approved by someone other than the author, then a payment batch
        Route::get('payment-proposals', [PaymentProposalController::class, 'index'])->name('treasury.payment-proposals.index');
        Route::post('payment-proposals', [PaymentProposalController::class, 'store'])->name('treasury.payment-proposals.store');
        Route::get('payment-proposals/{id}', [PaymentProposalController::class, 'show'])->whereUuid('id')->name('treasury.payment-proposals.show');
        Route::post('payment-proposals/{id}/approve', [PaymentProposalController::class, 'approve'])->whereUuid('id')->name('treasury.payment-proposals.approve');
        Route::post('payment-proposals/{id}/reject', [PaymentProposalController::class, 'reject'])->whereUuid('id')->name('treasury.payment-proposals.reject');

        // pagoPA payment notices of receivables (IUV, notice code, QR payload)
        Route::get('pagopa-notices', [PagoPaNoticeController::class, 'index'])->name('treasury.pagopa-notices.index');
        Route::post('pagopa-notices', [PagoPaNoticeController::class, 'store'])->name('treasury.pagopa-notices.store');
        Route::get('pagopa-notices/{id}', [PagoPaNoticeController::class, 'show'])->whereUuid('id')->name('treasury.pagopa-notices.show');
        Route::post('pagopa-notices/{id}/cancel', [PagoPaNoticeController::class, 'cancel'])->whereUuid('id')->name('treasury.pagopa-notices.cancel');
        Route::post('pagopa-notices/{id}/mark-paid', [PagoPaNoticeController::class, 'markPaid'])->whereUuid('id')->name('treasury.pagopa-notices.mark-paid');
        Route::get('pagopa-notices/{id}/qr-payload', [PagoPaNoticeController::class, 'qrPayload'])->whereUuid('id')->name('treasury.pagopa-notices.qr-payload');

        // Liquidity forecast with scenarios and recurring items, KPIs
        Route::get('liquidity/forecast', [LiquidityForecastController::class, 'forecast'])->name('treasury.liquidity.forecast');
        Route::get('forecast-scenarios', [LiquidityForecastController::class, 'scenarios'])->name('treasury.forecast-scenarios.index');
        Route::post('forecast-scenarios', [LiquidityForecastController::class, 'storeScenario'])->name('treasury.forecast-scenarios.store');
        Route::get('recurring-items', [LiquidityForecastController::class, 'recurringItems'])->name('treasury.recurring-items.index');
        Route::post('recurring-items', [LiquidityForecastController::class, 'storeRecurringItem'])->name('treasury.recurring-items.store');
        Route::delete('recurring-items/{id}', [LiquidityForecastController::class, 'destroyRecurringItem'])->whereUuid('id')->name('treasury.recurring-items.destroy');
        Route::get('kpis', [TreasuryKpiController::class, 'index'])->name('treasury.kpis');

        // Cash Positions & Liquidity Buffers
        Route::get('positions/intraday', [CashPositionController::class, 'intraday']);
        Route::get('positions/buffer', [CashPositionController::class, 'liquidityBuffer']);
        Route::get('positions/cash', [TreasuryBalanceController::class, 'position'])->name('treasury.positions.cash');

        // Payment Factory
        Route::get('payment-batches/{id}/download-xml', [PaymentBatchController::class, 'downloadXml']);
        Route::post('payment-batches/{id}/submit-approval', [PaymentBatchController::class, 'submitApproval']);
        Route::post('payment-batches/{id}/approve', [PaymentBatchController::class, 'approve']);
        Route::post('payment-batches/{id}/export', [PaymentBatchController::class, 'export']);
        Route::post('payment-batches/{id}/execute', [PaymentBatchController::class, 'execute']);
        Route::post('payment-batches/{id}/confirm', [PaymentBatchController::class, 'confirm']);
        Route::post('payment-batches/{id}/pain002', [PaymentBatchController::class, 'importPain002']);
        Route::post('payment-batches/{id}/cancel', [PaymentBatchController::class, 'cancel']);
        Route::apiResource('payment-batches', PaymentBatchController::class)->except(['update', 'destroy']);

        // Ri.Ba. collections: created and approved like any batch, exported to the CBI flat file
        Route::get('riba/batches', [RibaPortfolioController::class, 'batches'])->name('treasury.riba.index');
        Route::get('riba/portfolio', [RibaPortfolioController::class, 'portfolio'])->name('treasury.riba.portfolio');
        Route::post('riba/batches', [RibaController::class, 'store'])->name('treasury.riba.store');
        Route::get('riba/batches/{id}', [RibaController::class, 'show'])->name('treasury.riba.show');
        Route::post('riba/batches/{id}/export', [RibaController::class, 'export'])->name('treasury.riba.export');
        Route::get('riba/batches/{id}/file', [RibaController::class, 'file'])->name('treasury.riba.file');
        Route::post('riba/receipts/{id}/paid', [RibaController::class, 'paid'])->name('treasury.riba.paid');
        Route::post('riba/receipts/{id}/unpaid', [RibaController::class, 'unpaid'])->name('treasury.riba.unpaid');

        // Bank Statements & Reconciliations
        Route::get('reconciliations', [BankReconciliationController::class, 'index']);
        Route::post('statements/import', [BankReconciliationController::class, 'importStatement']);
        Route::post('reconciliations/auto-reconcile', [BankReconciliationController::class, 'runAutoReconciliation']);
        Route::post('reconciliations/lines/{lineId}/match', [BankReconciliationController::class, 'matchManually']);
        Route::post('reconciliations/{id}/approve', [BankReconciliationController::class, 'approve']);

        // IBAN Check & Open Banking (PSD2)
        Route::post('iban/check', [OpenBankingController::class, 'checkIban']);
        Route::post('open-banking/consents', [OpenBankingController::class, 'storeConsent']);
        Route::get('open-banking/consents/{id}/account', [OpenBankingController::class, 'account'])->whereUuid('id');
        Route::post('open-banking/payments', [OpenBankingController::class, 'initiatePayment']);
        Route::get('open-banking/payments/{id}', [OpenBankingController::class, 'showPayment'])->whereUuid('id');

        // Outgoing payment files: Swiss pain.001.001.09, SEPA pain.001.001.03, CBI
        Route::get('payment-flows', [PaymentFlowController::class, 'index'])->name('treasury.payment-flows.index');
        Route::post('payment-flows/preview', [PaymentFlowController::class, 'preview'])->name('treasury.payment-flows.preview');
        Route::post('payment-flows/batches', [PaymentFlowController::class, 'storeBatch'])->name('treasury.payment-flows.batches');

        // Modello F24 telematico
        Route::get('f24/tax-codes', [F24DelegationController::class, 'taxCodes'])->name('treasury.f24.tax-codes');
        Route::get('f24/delegations', [F24DelegationController::class, 'index'])->name('treasury.f24.index');
        Route::post('f24/delegations', [F24DelegationController::class, 'store'])->name('treasury.f24.store');
        Route::get('f24/delegations/{id}', [F24DelegationController::class, 'show'])->whereUuid('id')->name('treasury.f24.show');
        Route::put('f24/delegations/{id}', [F24DelegationController::class, 'update'])->whereUuid('id')->name('treasury.f24.update');
        Route::delete('f24/delegations/{id}', [F24DelegationController::class, 'destroy'])->whereUuid('id')->name('treasury.f24.destroy');
        Route::post('f24/delegations/{id}/validate', [F24DelegationController::class, 'validateDelegation'])->whereUuid('id')->name('treasury.f24.validate');
        Route::post('f24/delegations/{id}/generate', [F24DelegationController::class, 'generate'])->whereUuid('id')->name('treasury.f24.generate');
        Route::get('f24/delegations/{id}/download', [F24DelegationController::class, 'download'])->whereUuid('id')->name('treasury.f24.download');
        Route::post('f24/delegations/{id}/mark-paid', [F24DelegationController::class, 'markPaid'])->whereUuid('id')->name('treasury.f24.mark-paid');

        // Statement imports (camt.053 / camt.054 / CBI / CSV) and saved CSV mappings
        Route::post('statement-imports', [StatementImportController::class, 'import'])->name('treasury.statement-imports.store');
        Route::get('csv-profiles', [StatementImportController::class, 'profiles'])->name('treasury.csv-profiles.index');
        Route::post('csv-profiles', [StatementImportController::class, 'storeProfile'])->name('treasury.csv-profiles.store');
        Route::delete('csv-profiles/{id}', [StatementImportController::class, 'destroyProfile'])->whereUuid('id')->name('treasury.csv-profiles.destroy');

        // Smart reconciliation workbench: ranked proposals, 1-click apply, undo
        Route::get('smart-reconciliation/lines', [SmartReconciliationController::class, 'lines'])->name('treasury.smart-reconciliation.lines');
        Route::get('smart-reconciliation/summary', [SmartReconciliationController::class, 'summary'])->name('treasury.smart-reconciliation.summary');
        Route::post('smart-reconciliation/run', [SmartReconciliationController::class, 'run'])->name('treasury.smart-reconciliation.run');
        Route::post('smart-reconciliation/lines/{id}/refresh', [SmartReconciliationController::class, 'refresh'])->whereUuid('id')->name('treasury.smart-reconciliation.refresh');
        Route::post('smart-reconciliation/lines/{id}/unmatch', [SmartReconciliationController::class, 'unmatch'])->whereUuid('id')->name('treasury.smart-reconciliation.unmatch');
        Route::post('smart-reconciliation/proposals/{id}/apply', [SmartReconciliationController::class, 'apply'])->whereUuid('id')->name('treasury.smart-reconciliation.apply');
        Route::post('smart-reconciliation/proposals/{id}/reject', [SmartReconciliationController::class, 'reject'])->whereUuid('id')->name('treasury.smart-reconciliation.reject');

        // Open banking connections (PSD2 / SIX bLink) and their statement pulls
        Route::get('open-banking/status', [OpenBankingSyncController::class, 'status'])->name('treasury.open-banking.status');
        Route::get('open-banking/consents', [OpenBankingSyncController::class, 'consents'])->name('treasury.open-banking.consents.index');
        Route::get('open-banking/sync-runs', [OpenBankingSyncController::class, 'runs'])->name('treasury.open-banking.sync-runs');
        Route::post('open-banking/consents/{id}/sync', [OpenBankingSyncController::class, 'sync'])->whereUuid('id')->name('treasury.open-banking.sync');

        // Cash Forecasts
        Route::get('cash-forecasts', [CashForecastController::class, 'index']);
        Route::post('cash-forecasts/generate', [CashForecastController::class, 'generate']);
        Route::get('cash-forecasts/{id}', [CashForecastController::class, 'show']);
        Route::get('cash-forecasts/{id}/evaluate-deficits', [CashForecastController::class, 'evaluateDeficits']);

        // Receivable Collections & Dunning
        Route::get('collections/aging', [ReceivableCollectionController::class, 'aging']);
        Route::get('collections/dunning-readiness', [ReceivableCollectionController::class, 'evaluateDunning']);
        Route::post('collections/{id}/record', [ReceivableCollectionController::class, 'recordCollection']);
        Route::apiResource('collections', ReceivableCollectionController::class)->only(['index']);

        // Liquidity Management & Working Capital
        Route::get('liquidity/working-capital', [LiquidityController::class, 'workingCapital']);
        Route::post('liquidity/pools/{poolId}/sweep', [LiquidityController::class, 'sweepPool']);
        Route::post('liquidity/intercompany-transfer', [LiquidityController::class, 'intercompanyTransfer']);

        // Foreign Exchange Risk & Revaluation
        Route::get('fx/exposure', [FxRiskController::class, 'exposure']);
        Route::post('fx/revalue', [FxRiskController::class, 'revalue']);
    });
