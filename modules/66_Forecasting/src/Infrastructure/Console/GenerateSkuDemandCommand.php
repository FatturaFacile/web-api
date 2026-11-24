<?php

declare(strict_types=1);

namespace Modules\Forecasting\Infrastructure\Console;

use Modules\Forecasting\Application\Commands\GenerateSkuDemandForecastCommand;
use Modules\Forecasting\Application\Handlers\GenerateSkuDemandForecastHandler;

/**
 * Nightly: forecasts the demand of every product and warehouse of the tenant.
 */
final class GenerateSkuDemandCommand extends ForecastingTenantCommand
{
    protected $signature = 'forecasting:generate-sku-demand {--tenant= : Only this tenant id}';

    protected $description = 'Generate the SKU demand forecasts with safety stock and reorder point recommendations';

    public function handle(GenerateSkuDemandForecastHandler $handler): int
    {
        return $this->forEachTenant(static fn (string $tenantId): string => count($handler->handle(new GenerateSkuDemandForecastCommand($tenantId))).' SKU forecasts generated');
    }
}
