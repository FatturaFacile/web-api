<?php

declare(strict_types=1);

namespace Modules\Fleet\Services;

use Illuminate\Support\Carbon;
use Modules\Fleet\Models\FleetDeadline;
use Modules\Fleet\Models\FleetFuelEntry;
use Modules\Fleet\Models\FleetIncident;
use Modules\Fleet\Models\FleetScheduledService;
use Modules\Fleet\Models\FleetServiceRecord;
use Modules\Fleet\Models\FleetVehicle;

/**
 * Cost of ownership and the upcoming obligations of the fleet. The running cost of a vehicle is fuel and charging,
 * services, compliance deadlines and incidents that are not cancelled; the total cost of ownership adds the purchase
 * value. Amounts are summed as recorded, without currency conversion.
 */
final readonly class FleetDashboardService
{
    public function __construct(private ScheduledServiceService $schedules) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(string $tenantId, int $days): array
    {
        $vehicles = FleetVehicle::query()->where('tenant_id', $tenantId)->orderBy('license_plate')->get();
        $costs = $this->vehicleCosts($tenantId, $vehicles->all());
        $perVehicle = array_values($costs);

        $running = array_sum(array_column($perVehicle, 'running_cost'));
        $distance = array_sum(array_column($perVehicle, 'distance_km'));

        $today = Carbon::today();
        $deadlines = FleetDeadline::query()
            ->with('vehicle:id,license_plate,make,model')
            ->where('tenant_id', $tenantId)
            ->where('status', FleetDeadline::Open)
            ->whereDate('expires_on', '<=', $today->copy()->addDays($days)->toDateString())
            ->orderBy('expires_on')
            ->get();

        $overdue = FleetScheduledService::query()
            ->with('vehicle')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (FleetScheduledService $schedule): bool => $schedule->vehicle !== null && $this->schedules->dueStatus($schedule, $schedule->vehicle)['is_overdue'])
            ->values();

        $unpaid = FleetIncident::query()->where('tenant_id', $tenantId)->where('payment_status', FleetIncident::Unpaid)->get();

        return [
            'vehicles' => [
                'total' => $vehicles->count(),
                'by_status' => collect(FleetVehicle::Statuses)->mapWithKeys(static fn (string $status): array => [$status => $vehicles->where('status', $status)->count()])->all(),
            ],
            'totals' => [
                'fuel_cost' => self::money(array_sum(array_column($perVehicle, 'fuel_cost'))),
                'service_cost' => self::money(array_sum(array_column($perVehicle, 'service_cost'))),
                'deadline_cost' => self::money(array_sum(array_column($perVehicle, 'deadline_cost'))),
                'incident_cost' => self::money(array_sum(array_column($perVehicle, 'incident_cost'))),
                'running_cost' => self::money($running),
                'distance_km' => (int) $distance,
                'cost_per_km' => $distance > 0 ? number_format($running / $distance, 4, '.', '') : null,
            ],
            'per_vehicle' => array_map(static fn (array $row): array => self::format($row), $perVehicle),
            'upcoming_deadlines' => $deadlines->map(static fn (FleetDeadline $deadline): array => [
                'id' => $deadline->id,
                'vehicle_id' => $deadline->vehicle_id,
                'license_plate' => $deadline->vehicle?->license_plate,
                'deadline_type' => $deadline->deadline_type,
                'expires_on' => $deadline->expires_on->toDateString(),
                'days_to_expiry' => DeadlineService::daysToExpiry($deadline),
                'is_expired' => DeadlineService::daysToExpiry($deadline) < 0,
            ])->all(),
            'overdue_services' => $overdue->map(fn (FleetScheduledService $schedule): array => [
                'id' => $schedule->id,
                'vehicle_id' => $schedule->vehicle_id,
                'license_plate' => $schedule->vehicle?->license_plate,
                'name' => $schedule->name,
                ...$this->schedules->dueStatus($schedule, $schedule->vehicle),
            ])->all(),
            'unpaid_incidents' => [
                'count' => $unpaid->count(),
                'amount' => self::money((float) $unpaid->sum('amount')),
            ],
        ];
    }

    /**
     * Costs of one vehicle.
     *
     * @return array<string, mixed>
     */
    public function costsOf(FleetVehicle $vehicle): array
    {
        return self::format($this->vehicleCosts((string) $vehicle->tenant_id, [$vehicle])[$vehicle->id]);
    }

    /**
     * Raw cost figures per vehicle id.
     *
     * @param  list<FleetVehicle>  $vehicles
     * @return array<string, array<string, mixed>>
     */
    private function vehicleCosts(string $tenantId, array $vehicles): array
    {
        $ids = array_map(static fn (FleetVehicle $vehicle): string => $vehicle->id, $vehicles);

        $fuel = $this->sums(FleetFuelEntry::query(), $tenantId, $ids, 'total_cost');
        $service = $this->sums(FleetServiceRecord::query(), $tenantId, $ids, 'cost');
        $deadline = $this->sums(FleetDeadline::query(), $tenantId, $ids, 'cost');
        $incident = $this->sums(FleetIncident::query()->where('payment_status', '!=', FleetIncident::Cancelled), $tenantId, $ids, 'amount');
        $consumption = FleetFuelEntry::query()
            ->selectRaw('vehicle_id, AVG(consumption_per_100km) as average')
            ->where('tenant_id', $tenantId)
            ->whereIn('vehicle_id', $ids)
            ->whereNotNull('consumption_per_100km')
            ->groupBy('vehicle_id')
            ->pluck('average', 'vehicle_id');

        $rows = [];
        foreach ($vehicles as $vehicle) {
            $running = ($fuel[$vehicle->id] ?? 0.0) + ($service[$vehicle->id] ?? 0.0) + ($deadline[$vehicle->id] ?? 0.0) + ($incident[$vehicle->id] ?? 0.0);
            $distance = max(0, $vehicle->odometer_km - $vehicle->initial_odometer_km);

            $rows[$vehicle->id] = [
                'vehicle_id' => $vehicle->id,
                'license_plate' => $vehicle->license_plate,
                'make' => $vehicle->make,
                'model' => $vehicle->model,
                'status' => $vehicle->status,
                'odometer_km' => $vehicle->odometer_km,
                'distance_km' => $distance,
                'fuel_cost' => $fuel[$vehicle->id] ?? 0.0,
                'service_cost' => $service[$vehicle->id] ?? 0.0,
                'deadline_cost' => $deadline[$vehicle->id] ?? 0.0,
                'incident_cost' => $incident[$vehicle->id] ?? 0.0,
                'running_cost' => $running,
                'purchase_value' => $vehicle->purchase_value !== null ? (float) $vehicle->purchase_value : null,
                'total_cost_of_ownership' => $running + (float) ($vehicle->purchase_value ?? 0),
                'cost_per_km' => $distance > 0 ? $running / $distance : null,
                'average_consumption_per_100km' => isset($consumption[$vehicle->id]) ? (float) $consumption[$vehicle->id] : null,
            ];
        }

        return $rows;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @param  list<string>  $ids
     * @return array<string, float>
     */
    private function sums($query, string $tenantId, array $ids, string $column): array
    {
        return $query
            ->selectRaw("vehicle_id, SUM({$column}) as total")
            ->where('tenant_id', $tenantId)
            ->whereIn('vehicle_id', $ids)
            ->groupBy('vehicle_id')
            ->pluck('total', 'vehicle_id')
            ->map(static fn ($total): float => (float) $total)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function format(array $row): array
    {
        foreach (['fuel_cost', 'service_cost', 'deadline_cost', 'incident_cost', 'running_cost', 'total_cost_of_ownership'] as $key) {
            $row[$key] = self::money($row[$key]);
        }

        $row['purchase_value'] = $row['purchase_value'] !== null ? self::money($row['purchase_value']) : null;
        $row['cost_per_km'] = $row['cost_per_km'] !== null ? number_format($row['cost_per_km'], 4, '.', '') : null;
        $row['average_consumption_per_100km'] = $row['average_consumption_per_100km'] !== null ? number_format($row['average_consumption_per_100km'], 3, '.', '') : null;

        return $row;
    }

    private static function money(float|int $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
