<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Treasury\Http\Controllers\BankAccountController;
use Modules\Treasury\Http\Controllers\BankReconciliationController;
use Modules\Treasury\Http\Controllers\CashForecastController;
use Modules\Treasury\Http\Controllers\CashPositionController;
use Modules\Treasury\Http\Controllers\CashRegisterController;
use Modules\Treasury\Http\Controllers\FxRiskController;
use Modules\Treasury\Http\Controllers\LiquidityController;
use Modules\Treasury\Http\Controllers\LiquidityForecastController;
use Modules\Treasury\Http\Controllers\OpenBankingController;
use Modules\Treasury\Http\Controllers\PaymentBatchController;
use Modules\Treasury\Http\Controllers\PaymentProposalController;
use Modules\Treasury\Http\Controllers\ReceivableCollectionController;
use Modules\Treasury\Http\Controllers\RibaController;
use Modules\Treasury\Http\Controllers\RibaPortfolioController;
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
        Route::apiResource('bank-accounts', BankAccountController::class);

        // Internal transfers and treasury bank movements
        Route::get('movements', [TreasuryMovementController::class, 'index'])->name('treasury.movements.index');
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
