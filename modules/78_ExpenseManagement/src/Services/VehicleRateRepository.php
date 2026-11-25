<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Services;

use Carbon\CarbonInterface;
use Modules\ExpenseManagement\Models\AciVehicleRate;

/**
 * Finds the cost-per-km row of a vehicle valid on a date: the rows of the tenant first, then the global ones.
 */
final class VehicleRateRepository
{
    public function find(string $source, string $vehicleCode, CarbonInterface $onDate, ?int $annualKmBracket): ?AciVehicleRate
    {
        $date = $onDate->toDateString();

        $rows = AciVehicleRate::query()
            ->where('source', $source)
            ->where('vehicle_code', $vehicleCode)
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->orderByRaw('CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('valid_from')
            ->get();

        if ($annualKmBracket !== null) {
            $exact = $rows->firstWhere('annual_km_bracket', $annualKmBracket);

            if ($exact !== null) {
                return $exact;
            }
        }

        // Without a stated bracket, the first bracket of the table (the lowest annual mileage) applies.
        return $rows->sortBy('annual_km_bracket')->first();
    }
}
