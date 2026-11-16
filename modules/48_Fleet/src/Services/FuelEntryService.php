<?php

declare(strict_types=1);

namespace Modules\Fleet\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Fleet\Models\FleetFuelEntry;
use Modules\Fleet\Models\FleetVehicle;
use Modules\HumanResources\Models\Employee;

/**
 * Fuel and charging entries. After every change the consumption per 100 km and the cost per km of the whole vehicle are
 * recomputed: between two full tanks, the energy and the cost of every entry since the first one are spread over the
 * kilometres driven.
 */
final readonly class FuelEntryService
{
    private const array Fields = ['employee_id', 'entry_date', 'energy_unit', 'quantity', 'unit_price', 'total_cost', 'currency', 'odometer_km', 'is_full_tank', 'station', 'notes'];

    public function __construct(private FleetOdometerService $odometer) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): FleetFuelEntry
    {
        return DB::transaction(function () use ($tenantId, $data): FleetFuelEntry {
            $vehicle = $this->odometer->lockVehicle((string) $data['vehicle_id']);

            if ($vehicle->isRetired()) {
                throw ValidationException::withMessages(['vehicle_id' => [__('fleet_errors.vehicle.retired', ['status' => $vehicle->status])]]);
            }

            $data = $this->prepare($vehicle, $data, null);
            $entry = FleetFuelEntry::create([...array_intersect_key($data, array_flip(self::Fields)), 'tenant_id' => $tenantId, 'vehicle_id' => $vehicle->id]);
            $this->odometer->raise($vehicle, $entry->odometer_km);
            $this->recalculate($vehicle);

            return $entry->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FleetFuelEntry $entry, array $data): FleetFuelEntry
    {
        return DB::transaction(function () use ($entry, $data): FleetFuelEntry {
            $vehicle = $this->odometer->lockVehicle($entry->vehicle_id);
            $merged = [...$entry->only(self::Fields), ...$data];
            $merged = $this->prepare($vehicle, $merged, $entry, $data);

            $entry->update(array_intersect_key($merged, array_flip(self::Fields)));
            $this->odometer->raise($vehicle, $entry->odometer_km);
            $this->recalculate($vehicle);

            return $entry->refresh();
        });
    }

    public function delete(FleetFuelEntry $entry): void
    {
        DB::transaction(function () use ($entry): void {
            $vehicle = $this->odometer->lockVehicle($entry->vehicle_id);
            $entry->delete();
            $this->recalculate($vehicle);
        });
    }

    /**
     * Recomputes distance, consumption and cost per km of every entry of the vehicle.
     */
    public function recalculate(FleetVehicle $vehicle): void
    {
        $entries = FleetFuelEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->orderBy('odometer_km')
            ->orderBy('entry_date')
            ->orderBy('created_at')
            ->get();

        $anchor = null;
        $quantity = 0.0;
        $cost = 0.0;

        foreach ($entries as $entry) {
            $distance = null;
            $consumption = null;
            $costPerKm = null;

            if ($anchor !== null) {
                $quantity += (float) $entry->quantity;
                $cost += (float) $entry->total_cost;

                if ($entry->is_full_tank) {
                    $driven = $entry->odometer_km - $anchor;

                    if ($driven > 0) {
                        $distance = $driven;
                        $consumption = number_format($quantity / $driven * 100, 3, '.', '');
                        $costPerKm = number_format($cost / $driven, 4, '.', '');
                    }
                }
            }

            if ($entry->is_full_tank) {
                $anchor = $entry->odometer_km;
                $quantity = 0.0;
                $cost = 0.0;
            }

            if ($entry->distance_km !== $distance || $entry->consumption_per_100km !== $consumption || $entry->cost_per_km !== $costPerKm) {
                $entry->forceFill(['distance_km' => $distance, 'consumption_per_100km' => $consumption, 'cost_per_km' => $costPerKm])->saveQuietly();
            }
        }
    }

    /**
     * Validates the entry against the vehicle and fills the price or the total that is missing.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $changes  What the request changes: a changed price or quantity drops the old total.
     * @return array<string, mixed>
     */
    private function prepare(FleetVehicle $vehicle, array $data, ?FleetFuelEntry $current, ?array $changes = null): array
    {
        $unit = (string) $data['energy_unit'];

        if ($vehicle->fuel_type === 'electric' && $unit !== 'kwh') {
            throw ValidationException::withMessages(['energy_unit' => [__('fleet_errors.fuel.unit_electric')]]);
        }

        if (in_array($vehicle->fuel_type, ['petrol', 'diesel', 'lpg', 'cng', 'hydrogen'], true) && $unit === 'kwh') {
            throw ValidationException::withMessages(['energy_unit' => [__('fleet_errors.fuel.unit_not_electric')]]);
        }

        if (($data['employee_id'] ?? null) !== null && ! Employee::query()->whereKey($data['employee_id'])->where('tenant_id', $vehicle->tenant_id)->exists()) {
            throw ValidationException::withMessages(['employee_id' => [__('fleet_errors.assignment.employee_missing')]]);
        }

        if ($changes !== null && (isset($changes['quantity']) || isset($changes['unit_price'])) && ! isset($changes['total_cost'])) {
            $data['total_cost'] = null;
        } elseif ($changes !== null && isset($changes['total_cost']) && ! isset($changes['unit_price'])) {
            $data['unit_price'] = null;
        }

        $quantity = (float) $data['quantity'];

        if (($data['total_cost'] ?? null) === null && ($data['unit_price'] ?? null) !== null) {
            $data['total_cost'] = number_format($quantity * (float) $data['unit_price'], 2, '.', '');
        } elseif (($data['total_cost'] ?? null) !== null && ($data['unit_price'] ?? null) === null && $quantity > 0) {
            $data['unit_price'] = number_format((float) $data['total_cost'] / $quantity, 4, '.', '');
        }

        if (($data['total_cost'] ?? null) === null) {
            throw ValidationException::withMessages(['total_cost' => [__('fleet_errors.fuel.price_or_total')]]);
        }

        $this->odometer->assertReading($vehicle, Carbon::parse($data['entry_date'])->startOfDay(), (int) $data['odometer_km'], 'odometer_km', FleetFuelEntry::class, $current?->id);

        return $data;
    }
}
