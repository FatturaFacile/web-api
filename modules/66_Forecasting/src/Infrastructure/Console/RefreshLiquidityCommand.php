<?php

declare(strict_types=1);

namespace Modules\Forecasting\Infrastructure\Console;

use Modules\Forecasting\Application\Commands\RunAiLiquiditySimulationCommand;
use Modules\Forecasting\Application\Handlers\RunAiLiquiditySimulationHandler;

/**
 * Nightly: stores the 13-week and the 12-month liquidity projections of the tenant.
 */
final class RefreshLiquidityCommand extends ForecastingTenantCommand
{
    protected $signature = 'forecasting:refresh-liquidity {--tenant= : Only this tenant id}';

    protected $description = 'Refresh the 13-week and 12-month AI liquidity projections';

    public function handle(RunAiLiquiditySimulationHandler $handler): int
    {
        return $this->forEachTenant(static function (string $tenantId) use ($handler): string {
            $troughs = [];

            foreach ([91, 365] as $days) {
                $trough = $handler->handle(new RunAiLiquiditySimulationCommand($tenantId, $days))->toDomain()->cashTrough();
                $troughs[] = "{$days}d trough ".number_format((float) ($trough['balance'] ?? 0.0), 2, '.', '').' on '.($trough['date'] ?? '-');
            }

            return implode('; ', $troughs);
        });
    }
}
