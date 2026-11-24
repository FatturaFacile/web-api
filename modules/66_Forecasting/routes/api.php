<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Forecasting\Presentation\Http\Controllers\DemandForecastController;
use Modules\Forecasting\Presentation\Http\Controllers\LiquidityForecastController;
use Modules\Forecasting\Presentation\Http\Controllers\PaymentModelController;

// forecasting.view reads, forecasting.simulate trains, simulates and generates, forecasting.apply_wms applies (see config/permissions.php).
Route::prefix('api/v1/forecasting')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])
    ->group(function (): void {
        Route::middleware('module.access:forecasting')->group(function (): void {
            Route::get('payment-models', [PaymentModelController::class, 'index'])->name('forecasting.payment-models.index');
            Route::post('payment-models/train', [PaymentModelController::class, 'train'])->name('forecasting.payment-models.train');

            Route::post('liquidity/simulate', [LiquidityForecastController::class, 'simulate'])->name('forecasting.liquidity.simulate');
            Route::get('liquidity/confidence-bands', [LiquidityForecastController::class, 'confidenceBands'])->name('forecasting.liquidity.confidence-bands');

            Route::get('demand/forecasts', [DemandForecastController::class, 'index'])->name('forecasting.demand.index');
            Route::post('demand/generate', [DemandForecastController::class, 'generate'])->name('forecasting.demand.generate');
            Route::get('demand/accuracy', [DemandForecastController::class, 'accuracy'])->name('forecasting.demand.accuracy');
        });

        Route::post('demand/apply-reorder-rules', [DemandForecastController::class, 'applyReorderRules'])
            ->middleware('module.access:forecasting_wms')
            ->name('forecasting.demand.apply-reorder-rules');
    });
