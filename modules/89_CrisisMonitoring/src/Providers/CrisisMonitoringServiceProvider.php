<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\CrisisMonitoring\Console\ComputeCrisisIndicatorsCommand;

/**
 * Corporate crisis monitoring (Mod 357): the rolling DSCR, the Italian CCII early-warning indicators with the Art. 25-novies monitor and
 * the CNC practical test, the Swiss Art. 725, 725a and 725b CO guardrails with the Rangrücktritt registry, the stress test and the board
 * dossier. It reads the cash flows of the liquidity planning (Mod 017) and stores a daily snapshot.
 */
final class CrisisMonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([ComputeCrisisIndicatorsCommand::class]);
        }
    }
}
