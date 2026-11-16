<?php

declare(strict_types=1);

namespace Modules\Fleet\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Fleet\Models\FleetVehicle;

/**
 * Company vehicles: registration data, ownership and status. The odometer can be raised by hand but never lowered.
 */
final readonly class VehicleService
{
    private const array Fields = ['license_plate', 'vin', 'make', 'model', 'model_year', 'vehicle_type', 'fuel_type', 'status', 'ownership', 'acquired_on', 'contract_ends_on', 'purchase_value', 'currency', 'asset_id', 'notes'];

    public function __construct(private FleetOdometerService $odometer) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): FleetVehicle
    {
        $plate = self::normalizePlate((string) $data['license_plate']);
        $this->assertPlateFree($plate, null);
        $this->assertAsset($tenantId, $data['asset_id'] ?? null);
        $km = (int) ($data['odometer_km'] ?? 0);

        return FleetVehicle::create([
            ...array_intersect_key($data, array_flip(self::Fields)),
            'tenant_id' => $tenantId,
            'license_plate' => $plate,
            'initial_odometer_km' => $km,
            'odometer_km' => $km,
        ])->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FleetVehicle $vehicle, array $data): FleetVehicle
    {
        return DB::transaction(function () use ($vehicle, $data): FleetVehicle {
            $locked = $this->odometer->lockVehicle($vehicle->id);

            if (isset($data['license_plate'])) {
                $data['license_plate'] = self::normalizePlate((string) $data['license_plate']);
                $this->assertPlateFree($data['license_plate'], $locked->id);
            }

            if (array_key_exists('asset_id', $data)) {
                $this->assertAsset((string) $locked->tenant_id, $data['asset_id']);
            }

            if (isset($data['odometer_km']) && (int) $data['odometer_km'] < $locked->odometer_km) {
                throw ValidationException::withMessages(['odometer_km' => [__('fleet_errors.vehicle.odometer_decrease', ['current' => $locked->odometer_km])]]);
            }

            if (isset($data['status']) && in_array($data['status'], [FleetVehicle::Sold, FleetVehicle::Disposed], true) && $locked->assignments()->whereNull('ends_on')->exists()) {
                throw ValidationException::withMessages(['status' => [__('fleet_errors.vehicle.open_assignment')]]);
            }

            $locked->update([
                ...array_intersect_key($data, array_flip([...self::Fields, 'odometer_km'])),
            ]);

            return $locked->refresh();
        });
    }

    public static function normalizePlate(string $plate): string
    {
        return Str::upper(trim($plate));
    }

    private function assertPlateFree(string $plate, ?string $ignoreId): void
    {
        $taken = FleetVehicle::query()
            ->where('license_plate', $plate)
            ->when($ignoreId !== null, static fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['license_plate' => [__('fleet_errors.vehicle.plate_taken', ['plate' => $plate])]]);
        }
    }

    /**
     * The asset link is optional and only checked when the asset register exists.
     */
    private function assertAsset(string $tenantId, mixed $assetId): void
    {
        if ($assetId === null || ! Schema::hasTable('eam_fixed_assets')) {
            return;
        }

        if (! DB::table('eam_fixed_assets')->where('id', $assetId)->where('tenant_id', $tenantId)->exists()) {
            throw ValidationException::withMessages(['asset_id' => [__('fleet_errors.vehicle.asset_missing')]]);
        }
    }
}
