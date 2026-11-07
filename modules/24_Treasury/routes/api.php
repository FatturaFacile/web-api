<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Treasury\Http\Controllers\BankAccountController;
use Modules\Treasury\Http\Controllers\BankReconciliationController;
use Modules\Treasury\Http\Controllers\CashForecastController;
use Modules\Treasury\Http\Controllers\CashPositionController;
use Modules\Treasury\Http\Controllers\FxRiskController;
use Modules\Treasury\Http\Controllers\LiquidityController;
use Modules\Treasury\Http\Controllers\OpenBankingController;
use Modules\Treasury\Http\Controllers\PaymentBatchController;
use Modules\Treasury\Http\Controllers\ReceivableCollectionController;
use Modules\Treasury\Http\Controllers\RibaController;

Route::prefix('api/v1/treasury')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:treasury'])
    ->group(function (): void {
        // Bank Accounts Management
        Route::apiResource('bank-accounts', BankAccountController::class);

        // Cash Positions & Liquidity Buffers
        Route::get('positions/intraday', [CashPositionController::class, 'intraday']);
        Route::get('positions/buffer', [CashPositionController::class, 'liquidityBuffer']);

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
