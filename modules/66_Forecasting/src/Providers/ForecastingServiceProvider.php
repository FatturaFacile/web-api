<?php

declare(strict_types=1);

namespace Modules\Forecasting\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Forecasting\Domain\Contracts\ForecastingEngineInterface;
use Modules\Forecasting\Infrastructure\Console\GenerateSkuDemandCommand;
use Modules\Forecasting\Infrastructure\Console\RefreshLiquidityCommand;
use Modules\Forecasting\Infrastructure\Console\TrainPaymentModelsCommand;
use Modules\Forecasting\Infrastructure\Console\VerifyEndToEndCommand;
use Modules\Forecasting\Infrastructure\Services\HoltWintersForecastingEngine;

/**
 * AI cash flow and demand forecasting (Mod 094): routes, the forecasting engine binding and the console commands.
 * Bind another ForecastingEngineInterface to plug in a Prophet, SARIMAX or LightGBM service.
 */
final class ForecastingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ForecastingEngineInterface::class, HoltWintersForecastingEngine::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([TrainPaymentModelsCommand::class, RefreshLiquidityCommand::class, GenerateSkuDemandCommand::class, VerifyEndToEndCommand::class]);
        }
    }
}
