<?php

declare(strict_types=1);

namespace Modules\Fleet\Services;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Fleet\Models\FleetScheduledService;
use Modules\Fleet\Models\FleetServiceRecord;
use Modules\Fleet\Models\FleetVehicle;

/**
 * Scheduled services of a vehicle, due by date, by kilometres or both. A service is overdue once the due date has passed
 * or the odometer of the vehicle has reached the due kilometres.
 */
final readonly class ScheduledServiceService
{
    private const array Fields = ['name', 'service_type', 'interval_days', 'interval_km', 'last_service_on', 'last_service_km', 'next_due_on', 'next_due_km', 'is_active', 'notes'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, FleetVehicle $vehicle, array $data): FleetScheduledService
    {
        $schedule = new FleetScheduledService([...array_intersect_key($data, array_flip(self::Fields)), 'tenant_id' => $tenantId, 'vehicle_id' => $vehicle->id]);
        $this->fillDueFromIntervals($schedule, $vehicle, $data);
        $schedule->save();

        return $schedule->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FleetScheduledService $schedule, array $data): FleetScheduledService
    {
        $vehicle = $schedule->vehicle()->firstOrFail();
        $schedule->fill(array_intersect_key($data, array_flip(self::Fields)));
        $this->fillDueFromIntervals($schedule, $vehicle, $data);
        $schedule->save();

        return $schedule->refresh();
    }

    /**
     * Moves the schedule after the latest service done for it: the last service becomes that one and the next due date and
     * kilometres follow by the intervals. Without any service left the schedule keeps its due values.
     */
    public function syncWithRecords(FleetScheduledService $schedule): void
    {
        $latest = FleetServiceRecord::query()
            ->where('scheduled_service_id', $schedule->id)
            ->orderByDesc('service_date')
            ->orderByDesc('odometer_km')
            ->first();

        if ($latest === null) {
            $schedule->update(['last_service_on' => null, 'last_service_km' => null]);

            return;
        }

        $changes = ['last_service_on' => $latest->service_date, 'last_service_km' => $latest->odometer_km];

        if ($schedule->interval_days !== null) {
            $changes['next_due_on'] = $latest->service_date->copy()->addDays($schedule->interval_days);
        }

        if ($schedule->interval_km !== null) {
            $changes['next_due_km'] = $latest->odometer_km + $schedule->interval_km;
        }

        $schedule->update($changes);
    }

    /**
     * @return array{days_to_due: int|null, km_to_due: int|null, is_overdue: bool}
     */
    public function dueStatus(FleetScheduledService $schedule, FleetVehicle $vehicle): array
    {
        $days = $schedule->next_due_on !== null ? (int) Carbon::today()->diffInDays($schedule->next_due_on->copy()->startOfDay(), false) : null;
        $km = $schedule->next_due_km !== null ? $schedule->next_due_km - $vehicle->odometer_km : null;

        return [
            'days_to_due' => $days,
            'km_to_due' => $km,
            'is_overdue' => $schedule->is_active && (($days !== null && $days < 0) || ($km !== null && $km <= 0)),
        ];
    }

    /**
     * Computes the missing due values from the intervals: a date counts from the last service (or today), kilometres from
     * the last service reading (or the odometer of the vehicle).
     *
     * @param  array<string, mixed>  $data
     */
    private function fillDueFromIntervals(FleetScheduledService $schedule, FleetVehicle $vehicle, array $data): void
    {
        $touchedInterval = array_key_exists('interval_days', $data) || array_key_exists('last_service_on', $data);
        if ($schedule->interval_days !== null && ($schedule->next_due_on === null || ($touchedInterval && ! array_key_exists('next_due_on', $data)))) {
            $schedule->next_due_on = ($schedule->last_service_on ?? Carbon::today())->copy()->addDays($schedule->interval_days);
        }

        $touchedKm = array_key_exists('interval_km', $data) || array_key_exists('last_service_km', $data);
        if ($schedule->interval_km !== null && ($schedule->next_due_km === null || ($touchedKm && ! array_key_exists('next_due_km', $data)))) {
            $schedule->next_due_km = ($schedule->last_service_km ?? $vehicle->odometer_km) + $schedule->interval_km;
        }

        if ($schedule->next_due_on === null && $schedule->next_due_km === null) {
            throw ValidationException::withMessages(['interval_days' => [__('fleet_errors.schedule.due_required')]]);
        }
    }
}
