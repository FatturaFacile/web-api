<?php

declare(strict_types=1);

namespace Modules\Fleet\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Fleet\Models\FleetVehicle;
use Modules\Fleet\Models\FleetVehicleAssignment;
use Modules\HumanResources\Models\Employee;

/**
 * Driver assignments: a vehicle has one driver at a time. Periods are half-open (the day of the return is the day the
 * next driver can take the vehicle over), and the odometer at handover and at return joins the vehicle timeline.
 */
final readonly class AssignmentService
{
    public function __construct(private FleetOdometerService $odometer) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): FleetVehicleAssignment
    {
        return DB::transaction(function () use ($tenantId, $data): FleetVehicleAssignment {
            $vehicle = $this->odometer->lockVehicle((string) $data['vehicle_id']);

            if ($vehicle->isRetired()) {
                throw ValidationException::withMessages(['vehicle_id' => [__('fleet_errors.vehicle.retired', ['status' => $vehicle->status])]]);
            }

            $data['start_odometer_km'] = (int) ($data['start_odometer_km'] ?? $vehicle->odometer_km);
            $this->assertValid($vehicle, $data, null);

            $assignment = FleetVehicleAssignment::create([...$this->fields($data), 'tenant_id' => $tenantId, 'vehicle_id' => $vehicle->id]);
            $this->raiseOdometer($vehicle, $assignment);

            return $assignment->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FleetVehicleAssignment $assignment, array $data): FleetVehicleAssignment
    {
        return DB::transaction(function () use ($assignment, $data): FleetVehicleAssignment {
            $vehicle = $this->odometer->lockVehicle($assignment->vehicle_id);
            $merged = [...$assignment->only(['employee_id', 'driver_label', 'starts_on', 'ends_on', 'start_odometer_km', 'end_odometer_km']), ...$data];
            $this->assertValid($vehicle, $merged, $assignment);

            $assignment->update($this->fields($data));
            $this->raiseOdometer($vehicle, $assignment);

            return $assignment->refresh();
        });
    }

    /**
     * Ends an open assignment with the date and odometer of the return.
     *
     * @param  array<string, mixed>  $data
     */
    public function end(FleetVehicleAssignment $assignment, array $data): FleetVehicleAssignment
    {
        if ($assignment->ends_on !== null) {
            throw ValidationException::withMessages(['ends_on' => [__('fleet_errors.assignment.already_ended')]]);
        }

        return $this->update($assignment, [
            'ends_on' => $data['ends_on'],
            'end_odometer_km' => $data['end_odometer_km'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertValid(FleetVehicle $vehicle, array $data, ?FleetVehicleAssignment $current): void
    {
        $employeeId = $data['employee_id'] ?? null;
        $label = $data['driver_label'] ?? null;

        if ($employeeId === null && ($label === null || trim((string) $label) === '')) {
            throw ValidationException::withMessages(['employee_id' => [__('fleet_errors.assignment.driver_required')]]);
        }

        if ($employeeId !== null && ! Employee::query()->whereKey($employeeId)->where('tenant_id', $vehicle->tenant_id)->exists()) {
            throw ValidationException::withMessages(['employee_id' => [__('fleet_errors.assignment.employee_missing')]]);
        }

        $starts = Carbon::parse($data['starts_on'])->startOfDay();
        $ends = ($data['ends_on'] ?? null) !== null ? Carbon::parse($data['ends_on'])->startOfDay() : null;
        $startKm = (int) $data['start_odometer_km'];
        $endKm = ($data['end_odometer_km'] ?? null) !== null ? (int) $data['end_odometer_km'] : null;

        if ($endKm !== null && $endKm < $startKm) {
            throw ValidationException::withMessages(['end_odometer_km' => [__('fleet_errors.assignment.end_odometer_low')]]);
        }

        $this->assertNoOverlap($vehicle, $starts, $ends, $current);
        $this->odometer->assertReading($vehicle, $starts, $startKm, 'start_odometer_km', FleetVehicleAssignment::class, $current?->id);

        if ($ends !== null && $endKm !== null) {
            $this->odometer->assertReading($vehicle, $ends, $endKm, 'end_odometer_km', FleetVehicleAssignment::class, $current?->id);
        }
    }

    private function assertNoOverlap(FleetVehicle $vehicle, CarbonInterface $starts, ?CarbonInterface $ends, ?FleetVehicleAssignment $current): void
    {
        $others = FleetVehicleAssignment::query()
            ->where('vehicle_id', $vehicle->id)
            ->when($current !== null, static fn ($query) => $query->whereKeyNot($current->id))
            ->get();

        foreach ($others as $other) {
            if ($this->overlaps($starts, $ends, $other->starts_on, $other->ends_on)) {
                throw ValidationException::withMessages(['starts_on' => [__('fleet_errors.assignment.overlap', [
                    'from' => $other->starts_on->toDateString(),
                    'to' => $other->ends_on?->toDateString() ?? '...',
                ])]]);
            }
        }
    }

    /**
     * Half-open periods: [start, end) with no end meaning open-ended; a period that starts and ends the same day holds
     * that day.
     */
    private function overlaps(CarbonInterface $startA, ?CarbonInterface $endA, CarbonInterface $startB, ?CarbonInterface $endB): bool
    {
        $limitA = $endA === null ? null : ($endA->lte($startA) ? $startA->copy()->addDay() : $endA);
        $limitB = $endB === null ? null : ($endB->lte($startB) ? $startB->copy()->addDay() : $endB);

        return ($limitB === null || $startA->lt($limitB)) && ($limitA === null || $startB->lt($limitA));
    }

    private function raiseOdometer(FleetVehicle $vehicle, FleetVehicleAssignment $assignment): void
    {
        $this->odometer->raise($vehicle, max($assignment->start_odometer_km, (int) $assignment->end_odometer_km));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        return array_intersect_key($data, array_flip(['employee_id', 'driver_label', 'starts_on', 'ends_on', 'start_odometer_km', 'end_odometer_km', 'notes']));
    }
}
