<?php

declare(strict_types=1);

namespace Modules\Planning\Services;

use Carbon\CarbonInterface;
use Modules\Manufacturing\Models\MfgDemandForecast;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Planning\Contracts\DemandForecastSource;

/**
 * Default demand forecast source: the forecasts and the items of the manufacturing module. It is the only class of the
 * planning module that knows the manufacturing models.
 */
final class ManufacturingDemandForecastSource implements DemandForecastSource
{
    public function forecasts(string $tenantId, CarbonInterface $from, CarbonInterface $to): array
    {
        return MfgDemandForecast::query()
            ->with('mfgItem:id,item_code,name')
            ->where('tenant_id', $tenantId)
            ->whereDate('forecast_date', '>=', $from->toDateString())
            ->whereDate('forecast_date', '<=', $to->toDateString())
            ->orderBy('forecast_date')
            ->get()
            ->filter(static fn (MfgDemandForecast $forecast): bool => $forecast->mfgItem !== null)
            ->map(static fn (MfgDemandForecast $forecast): array => [
                'item_id' => $forecast->mfg_item_id,
                'item_code' => (string) $forecast->mfgItem?->item_code,
                'item_name' => $forecast->mfgItem?->name,
                'forecast_date' => $forecast->forecast_date->toDateString(),
                'quantity' => (string) $forecast->quantity,
            ])
            ->values()
            ->all();
    }

    public function findItem(string $tenantId, string $itemId): ?array
    {
        $item = MfgItem::query()->where('tenant_id', $tenantId)->whereKey($itemId)->first();

        return $item === null ? null : ['item_id' => $item->id, 'item_code' => $item->item_code, 'item_name' => $item->name];
    }
}
