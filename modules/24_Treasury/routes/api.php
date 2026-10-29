<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Treasury\Http\Controllers\BankAccountController;
use Modules\Treasury\Http\Controllers\BankReconciliationController;
use Modules\Treasury\Http\Controllers\CashForecastController;
use Modules\Treasury\Http\Controllers\CashPositionController;
use Modules\Treasury\Http\Controllers\FxRiskController;
use Modules\Treasury\Http\Controllers\LiquidityController;
use Modules\Treasury\Http\Controllers\PaymentBatchController;
use Modules\Treasury\Http\Controllers\ReceivableCollectionController;

Route::prefix('api/v1/treasury')
    ->middleware(['api'])
    ->group(function (): void {
        // Bank Accounts Management
        Route::apiResource('bank-accounts', BankAccountController::class);

        // Cash Positions & Liquidity Buffers
        Route::get('positions/intraday', [CashPositionController::class, 'intraday']);
        Route::get('positions/buffer', [CashPositionController::class, 'liquidityBuffer']);

        // Payment Factory
        Route::get('payment-batches/{id}/download-xml', [PaymentBatchController::class, 'downloadXml']);
        Route::post('payment-batches/{id}/submit-approval', [PaymentBatchController::class, 'submitApproval']);
        Route::post('payment-batches/{id}/execute', [PaymentBatchController::class, 'execute']);
        Route::post('payment-batches/{id}/cancel', [PaymentBatchController::class, 'cancel']);
        Route::apiResource('payment-batches', PaymentBatchController::class);

        // Bank Statements & Reconciliations
        Route::get('reconciliations', [BankReconciliationController::class, 'index']);
        Route::post('statements/import', [BankReconciliationController::class, 'importStatement']);
        Route::post('reconciliations/auto-reconcile', [BankReconciliationController::class, 'runAutoReconciliation']);
        Route::post('reconciliations/lines/{lineId}/match', [BankReconciliationController::class, 'matchManually']);
        Route::post('reconciliations/{id}/approve', [BankReconciliationController::class, 'approve']);

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
