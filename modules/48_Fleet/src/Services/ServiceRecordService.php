<?php

declare(strict_types=1);

namespace Modules\Fleet\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Fleet\Models\FleetScheduledService;
use Modules\Fleet\Models\FleetServiceRecord;
use Modules\Fleet\Models\FleetVehicle;

/**
 * Service and maintenance records. A record that completes a scheduled service moves that schedule forward.
 */
final readonly class ServiceRecordService
{
    private const array Fields = ['scheduled_service_id', 'service_type', 'service_date', 'odometer_km', 'vendor', 'description', 'reference', 'cost', 'currency', 'notes'];

    public function __construct(private FleetOdometerService $odometer, private ScheduledServiceService $schedules) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): FleetServiceRecord
    {
        return DB::transaction(function () use ($tenantId, $data): FleetServiceRecord {
            $vehicle = $this->odometer->lockVehicle((string) $data['vehicle_id']);
            $this->assertValid($vehicle, $data, null);

            $record = FleetServiceRecord::create([...array_intersect_key($data, array_flip(self::Fields)), 'tenant_id' => $tenantId, 'vehicle_id' => $vehicle->id]);
            $this->odometer->raise($vehicle, $record->odometer_km);
            $this->syncSchedule($record->scheduled_service_id);

            return $record->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FleetServiceRecord $record, array $data): FleetServiceRecord
    {
        return DB::transaction(function () use ($record, $data): FleetServiceRecord {
            $vehicle = $this->odometer->lockVehicle($record->vehicle_id);
            $previousSchedule = $record->scheduled_service_id;
            $this->assertValid($vehicle, [...$record->only(self::Fields), ...$data], $record);

            $record->update(array_intersect_key($data, array_flip(self::Fields)));
            $this->odometer->raise($vehicle, $record->odometer_km);
            $this->syncSchedule($previousSchedule);
            $this->syncSchedule($record->scheduled_service_id);

            return $record->refresh();
        });
    }

    public function delete(FleetServiceRecord $record): void
    {
        DB::transaction(function () use ($record): void {
            $scheduleId = $record->scheduled_service_id;
            $record->delete();
            $this->syncSchedule($scheduleId);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertValid(FleetVehicle $vehicle, array $data, ?FleetServiceRecord $current): void
    {
        if (($data['scheduled_service_id'] ?? null) !== null) {
            $schedule = FleetScheduledService::query()->find($data['scheduled_service_id']);

            if ($schedule === null || $schedule->vehicle_id !== $vehicle->id) {
                throw ValidationException::withMessages(['scheduled_service_id' => [__('fleet_errors.service.schedule_mismatch')]]);
            }
        }

        $this->odometer->assertReading($vehicle, Carbon::parse($data['service_date'])->startOfDay(), (int) $data['odometer_km'], 'odometer_km', FleetServiceRecord::class, $current?->id);
    }

    private function syncSchedule(?string $scheduleId): void
    {
        $schedule = $scheduleId !== null ? FleetScheduledService::query()->find($scheduleId) : null;

        if ($schedule !== null) {
            $this->schedules->syncWithRecords($schedule);
        }
    }
}
