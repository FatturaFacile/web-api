<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\LiquidityPlanning\Console\EvaluateLiquidityAlertsCommand;
use Modules\LiquidityPlanning\Services\ForecastService;
use Modules\LiquidityPlanning\Services\IndicatorService;
use Modules\LiquidityPlanning\Sources\CapexDebtSource;
use Modules\LiquidityPlanning\Sources\ContractsSource;
use Modules\LiquidityPlanning\Sources\LedgerDueDatesSource;
use Modules\LiquidityPlanning\Sources\ManualItemsSource;
use Modules\LiquidityPlanning\Sources\OrdersSource;
use Modules\LiquidityPlanning\Sources\PersonnelTaxSource;
use Modules\LiquidityPlanning\Support\ProjectionBuilder;
use Modules\LiquidityPlanning\Support\ScenarioApplier;

/**
 * Cash flow forecasting and liquidity planning (Mod 017): the rolling forecast over the ledgers in three reliability tiers, the
 * crisis early-warning indicators (DSCR, capital, PFN), the what-if scenarios, the alerts and the board / bank report. Loads
 * the routes and registers the daily alert evaluation.
 */
final class LiquidityPlanningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ForecastService::class, fn ($app): ForecastService => new ForecastService(
            [
                new LedgerDueDatesSource,
                new OrdersSource,
                new ContractsSource,
                new PersonnelTaxSource,
                new CapexDebtSource,
                new ManualItemsSource,
            ],
            $app->make(ProjectionBuilder::class),
            $app->make(ScenarioApplier::class),
            $app->make(IndicatorService::class),
        ));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([EvaluateLiquidityAlertsCommand::class]);
        }
    }
}
