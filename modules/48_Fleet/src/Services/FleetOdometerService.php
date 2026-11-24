<?php

declare(strict_types=1);

namespace Modules\Fleet\Services;

use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use Modules\Fleet\Models\FleetFuelEntry;
use Modules\Fleet\Models\FleetServiceRecord;
use Modules\Fleet\Models\FleetVehicle;
use Modules\Fleet\Models\FleetVehicleAssignment;

/**
 * Keeps the odometer of a vehicle monotonic. Every reading (fuel entry, service, assignment handover and return) must
 * agree with the other readings on the timeline, and the odometer of the vehicle follows the highest reading and never
 * goes down.
 */
final class FleetOdometerService
{
    /**
     * The vehicle row locked for the rest of the transaction.
     */
    public function lockVehicle(string $vehicleId): FleetVehicle
    {
        return FleetVehicle::query()->whereKey($vehicleId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Refuses a reading that contradicts the readings before or after it, or that is lower than the vehicle odometer when
     * it is the latest one.
     *
     * @param  class-string|null  $ignoreModel  Model class of the record being changed, left out of the comparison.
     */
    public function assertReading(FleetVehicle $vehicle, CarbonInterface $date, int $km, string $field, ?string $ignoreModel = null, ?string $ignoreId = null): void
    {
        $hasLater = false;

        foreach ($this->readings($vehicle, $ignoreModel, $ignoreId) as $reading) {
            if ($reading['date']->lte($date) && $reading['km'] > $km) {
                throw ValidationException::withMessages([$field => [__('fleet_errors.odometer.below_earlier', ['km' => $km, 'earlier' => $reading['km'], 'date' => $reading['date']->toDateString()])]]);
            }

            if ($reading['date']->gte($date) && $reading['km'] < $km) {
                throw ValidationException::withMessages([$field => [__('fleet_errors.odometer.above_later', ['km' => $km, 'later' => $reading['km'], 'date' => $reading['date']->toDateString()])]]);
            }

            $hasLater = $hasLater || $reading['date']->gt($date);
        }

        if (! $hasLater && $km < $vehicle->odometer_km) {
            throw ValidationException::withMessages([$field => [__('fleet_errors.vehicle.odometer_decrease', ['current' => $vehicle->odometer_km])]]);
        }
    }

    /**
     * Moves the odometer of the vehicle up to the reading; a lower reading never lowers it.
     */
    public function raise(FleetVehicle $vehicle, int $km): void
    {
        if ($km > $vehicle->odometer_km) {
            $vehicle->update(['odometer_km' => $km]);
        }
    }

    /**
     * @return list<array{date: CarbonInterface, km: int}>
     */
    private function readings(FleetVehicle $vehicle, ?string $ignoreModel, ?string $ignoreId): array
    {
        $skip = static fn (string $model, string $id): bool => $ignoreModel === $model && $ignoreId === $id;
        $readings = [];

        foreach (FleetFuelEntry::query()->where('vehicle_id', $vehicle->id)->get(['id', 'entry_date', 'odometer_km']) as $entry) {
            if (! $skip(FleetFuelEntry::class, $entry->id)) {
                $readings[] = ['date' => $entry->entry_date, 'km' => $entry->odometer_km];
            }
        }

        foreach (FleetServiceRecord::query()->where('vehicle_id', $vehicle->id)->get(['id', 'service_date', 'odometer_km']) as $record) {
            if (! $skip(FleetServiceRecord::class, $record->id)) {
                $readings[] = ['date' => $record->service_date, 'km' => $record->odometer_km];
            }
        }

        foreach (FleetVehicleAssignment::query()->where('vehicle_id', $vehicle->id)->get(['id', 'starts_on', 'ends_on', 'start_odometer_km', 'end_odometer_km']) as $assignment) {
            if ($skip(FleetVehicleAssignment::class, $assignment->id)) {
                continue;
            }

            $readings[] = ['date' => $assignment->starts_on, 'km' => $assignment->start_odometer_km];

            if ($assignment->ends_on !== null && $assignment->end_odometer_km !== null) {
                $readings[] = ['date' => $assignment->ends_on, 'km' => $assignment->end_odometer_km];
            }
        }

        return $readings;
    }
}
