<?php

declare(strict_types=1);

namespace Modules\Forecasting\Application\Queries;

/**
 * MAPE and WAPE of the forecasts whose period has closed.
 */
final readonly class GetSkuForecastVsActualAccuracyQuery
{
    public function __construct(
        public string $tenantId,
        public ?string $productId = null,
        public ?string $warehouseId = null,
    ) {}
}
