<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\AnalyticalAccounting\Models\AllocationDriver;
use Modules\AnalyticalAccounting\Models\DriverValue;

/**
 * Values of the allocation drivers per center and period, and the system drivers every company starts with.
 */
final class DriverValueService
{
    /**
     * @return LengthAwarePaginator<int, DriverValue>
     */
    public function list(string $tenantId, string $driverId, ?string $periodKey, int $perPage): LengthAwarePaginator
    {
        $query = DriverValue::query()->where('tenant_id', $tenantId)->where('allocation_driver_id', $driverId)->orderBy('period_key');
        if ($periodKey !== null) {
            $query->where('period_key', $periodKey);
        }

        return $query->paginate($perPage);
    }

    /**
     * @param  list<array{analytical_center_id: string, value: float|int|string}>  $values
     * @return list<DriverValue>
     */
    public function upsert(string $tenantId, string $driverId, string $periodKey, array $values, ?string $userId = null): array
    {
        return DB::transaction(function () use ($tenantId, $driverId, $periodKey, $values, $userId): array {
            $out = [];
            foreach ($values as $row) {
                $existing = DriverValue::query()->where('tenant_id', $tenantId)->where('allocation_driver_id', $driverId)
                    ->where('analytical_center_id', $row['analytical_center_id'])->where('period_key', $periodKey)->first();
                if ($existing === null) {
                    $out[] = DriverValue::create(['tenant_id' => $tenantId, 'allocation_driver_id' => $driverId, 'analytical_center_id' => $row['analytical_center_id'], 'period_key' => $periodKey, 'value' => $row['value'], 'created_by' => $userId]);
                } else {
                    $existing->update(['value' => $row['value'], 'updated_by' => $userId]);
                    $out[] = $existing;
                }
            }

            return $out;
        });
    }

    /**
     * Creates the four system drivers of a company when missing (fixed percentages carry their weights on the step).
     */
    public function ensureSystemDrivers(string $tenantId): void
    {
        $defaults = [
            ['AREA_SQM', 'Occupied area', 'AREA_SQM', 'm2'],
            ['HEADCOUNT_FTE', 'Headcount (FTE)', 'HEADCOUNT_FTE', 'FTE'],
            ['MACHINE_HOURS', 'Machine hours', 'MACHINE_HOURS', 'h'],
            ['REVENUE_VOLUME', 'Revenue volume', 'REVENUE_VOLUME', 'CHF'],
            ['FIXED_PERCENTAGE', 'Fixed percentages', 'FIXED_PERCENTAGE', '%'],
        ];
        foreach ($defaults as [$code, $name, $type, $unit]) {
            AllocationDriver::query()->firstOrCreate(['tenant_id' => $tenantId, 'code' => $code], ['name' => $name, 'driver_type' => $type, 'unit' => $unit, 'is_system' => true, 'is_active' => true]);
        }
    }
}
