<?php

declare(strict_types=1);

namespace Modules\Planning\Contracts;

use Carbon\CarbonInterface;

/**
 * Where the planning module reads the demand forecasts and the item master from. The default implementation reads the
 * forecasts of the manufacturing module; a company may bind another source (a sales forecast, an external system)
 * without the planning code knowing it.
 */
interface DemandForecastSource
{
    /**
     * Forecast quantities per item, one entry per forecast record, between the two dates (inclusive).
     *
     * @return list<array{item_id: string|null, item_code: string, item_name: string|null, forecast_date: string, quantity: string}>
     */
    public function forecasts(string $tenantId, CarbonInterface $from, CarbonInterface $to): array;

    /**
     * An item of the item master of the company, or null when it does not exist.
     *
     * @return array{item_id: string, item_code: string, item_name: string|null}|null
     */
    public function findItem(string $tenantId, string $itemId): ?array;
}
