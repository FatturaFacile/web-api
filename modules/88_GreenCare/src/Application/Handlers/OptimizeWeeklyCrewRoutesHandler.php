<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Handlers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\GreenCare\Application\Commands\OptimizeWeeklyCrewRoutesCommand;
use Modules\GreenCare\Application\Services\DueInterventionPlanner;
use Modules\GreenCare\Application\Services\RoutePlanner;
use Modules\GreenCare\Domain\Enums\BillingStatus;
use Modules\GreenCare\Domain\Enums\ContractStatus;
use Modules\GreenCare\Domain\Enums\RouteStatus;
use Modules\GreenCare\Domain\Enums\WeatherAlertStatus;
use Modules\GreenCare\Domain\Exceptions\GreenCareException;
use Modules\GreenCare\Domain\Support\GeoMath;
use Modules\GreenCare\Domain\Weather\IWeatherForecastProvider;
use Modules\GreenCare\Domain\Weather\WeatherAlertEvaluator;
use Modules\GreenCare\Domain\Weather\WeatherForecast;
use Modules\GreenCare\Models\CrewRouteSchedule;
use Modules\GreenCare\Models\CrewRouteStop;
use Modules\GreenCare\Models\CrewTeam;
use Modules\GreenCare\Models\SeasonalMaintenanceContract;

/**
 * Plans the week: the active seasonal contracts that are due for a visit are grouped by geographic proximity (Haversine), assigned to the
 * crews within their daily capacity and equipment, ordered into routes, and the days are chosen with the weather outlook (clear days first).
 * Re-running the planner for the same week adds only the contracts that are still without a visit.
 */
final class OptimizeWeeklyCrewRoutesHandler
{
    public function __construct(
        private readonly RoutePlanner $planner,
        private readonly DueInterventionPlanner $due,
        private readonly IWeatherForecastProvider $weather,
        private readonly WeatherAlertEvaluator $alerts,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @return array{routes: Collection<int, CrewRouteSchedule>, unassigned: list<array{contract_id: string, site_id: string, site_name: string, reason: string}>, summary: array{routes_created: int, stops_created: int}}
     *
     * @throws GreenCareException week_start_invalid, no_crews_available
     */
    public function handle(OptimizeWeeklyCrewRoutesCommand $command): array
    {
        $weekStart = $command->weekStart->copy()->startOfDay();

        if (! $weekStart->isMonday()) {
            throw GreenCareException::because('week_start_invalid');
        }

        $crews = CrewTeam::query()
            ->where('is_active', true)
            ->when($command->crewTeamIds !== null && $command->crewTeamIds !== [], static fn ($query) => $query->whereIn('id', (array) $command->crewTeamIds))
            ->orderBy('code')
            ->get();

        if ($crews->isEmpty()) {
            throw GreenCareException::because('no_crews_available');
        }

        return DB::transaction(function () use ($command, $weekStart, $crews): array {
            $weekEnd = $weekStart->copy()->addDays(6);
            $contracts = $this->dueContracts($weekStart, $weekEnd);
            $candidates = $contracts->map(static fn (SeasonalMaintenanceContract $contract): array => [
                'key' => (string) $contract->id,
                'lat' => $contract->site->latitude === null ? null : (float) $contract->site->latitude,
                'lng' => $contract->site->longitude === null ? null : (float) $contract->site->longitude,
                'minutes' => self::visitMinutes($contract),
                'activities' => RoutePlanner::activitiesFor($contract->included_services ?? []),
            ])->values()->all();

            $days = $this->workingDays($weekStart);
            $forecasts = $command->refreshWeather ? $this->forecasts($days, $candidates, $crews) : [];
            $orderedDays = $this->clearDaysFirst($days, $forecasts);
            $plan = $this->planner->plan($candidates, $this->crewInputs($crews, $weekStart, $weekEnd), $orderedDays);

            $byContract = $contracts->keyBy(static fn (SeasonalMaintenanceContract $contract): string => (string) $contract->id);
            $schedules = [];
            $stopsCreated = 0;
            $routesCreated = 0;

            foreach ($plan['routes'] as $route) {
                /** @var CrewTeam $crew */
                $crew = $crews->firstWhere('id', $route['crew_id']);
                [$schedule, $created] = $this->scheduleFor($crew, $route['day'], $forecasts[$route['day']] ?? null);
                $routesCreated += $created ? 1 : 0;
                $order = (int) CrewRouteStop::query()->where('route_schedule_id', $schedule->id)->max('stop_order');

                foreach ($route['keys'] as $key) {
                    /** @var SeasonalMaintenanceContract $contract */
                    $contract = $byContract[$key];
                    CrewRouteStop::query()->create([
                        'tenant_id' => (string) $this->tenants->getTenantId(),
                        'route_schedule_id' => $schedule->id,
                        'site_id' => $contract->site_id,
                        'contract_id' => $contract->id,
                        'stop_order' => ++$order,
                        'planned_activities' => RoutePlanner::activitiesFor($contract->included_services ?? []),
                        'estimated_duration_minutes' => self::visitMinutes($contract),
                        'counts_toward_contract_passage' => true,
                        'billing_status' => BillingStatus::NotBilled->value,
                    ]);
                    $stopsCreated++;
                }

                $metadata = $schedule->route_optimization_metadata ?? ['total_km' => 0.0, 'estimated_duration_minutes' => 0];
                $schedule->route_optimization_metadata = [
                    'total_km' => round((float) $metadata['total_km'] + $route['total_km'], 1),
                    'estimated_duration_minutes' => (int) $metadata['estimated_duration_minutes'] + $route['estimated_duration_minutes'],
                    'optimized_at' => now()->toIso8601String(),
                ];
                $schedule->save();
                $schedules[$schedule->id] = $schedule;
            }

            $unassigned = array_map(static fn (array $row): array => [
                'contract_id' => $row['key'],
                'site_id' => (string) $byContract[$row['key']]->site_id,
                'site_name' => (string) $byContract[$row['key']]->site->name,
                'reason' => $row['reason'],
            ], $plan['unassigned']);

            return [
                'routes' => CrewRouteSchedule::query()->with(['crew', 'stops.site', 'stops.contract'])->whereKey(array_keys($schedules))->orderBy('schedule_date')->orderBy('crew_team_id')->get(),
                'unassigned' => $unassigned,
                'summary' => ['routes_created' => $routesCreated, 'stops_created' => $stopsCreated],
            ];
        });
    }

    /**
     * Minutes of a visit from the size of the site: lawn, hedges and trees.
     */
    public static function visitMinutes(SeasonalMaintenanceContract $contract): int
    {
        $site = $contract->site;
        $minutes = 15 + (float) $site->lawn_area_sqm / 60 + (float) $site->hedge_length_meters / 5 + $site->tree_count * 2;

        return (int) max(30, min(480, round($minutes)));
    }

    /**
     * @return Collection<int, SeasonalMaintenanceContract>
     */
    private function dueContracts(Carbon $weekStart, Carbon $weekEnd): Collection
    {
        /** @var Collection<int, SeasonalMaintenanceContract> $contracts */
        $contracts = SeasonalMaintenanceContract::query()
            ->with('site')
            ->where('status', ContractStatus::Active->value)
            ->whereDate('season_start_date', '<=', $weekEnd->toDateString())
            ->whereDate('season_end_date', '>=', $weekStart->toDateString())
            ->orderBy('contract_number')
            ->get();

        $pending = CrewRouteStop::query()
            ->whereNotNull('contract_id')
            ->whereNull('actual_checkout_at')
            ->whereHas('schedule', static fn ($query) => $query->whereIn('status', [RouteStatus::Planned->value, RouteStatus::InProgress->value]))
            ->selectRaw('contract_id, count(*) as pending_count')
            ->groupBy('contract_id')
            ->pluck('pending_count', 'contract_id');

        return $contracts->filter(fn (SeasonalMaintenanceContract $contract): bool => $contract->site->is_active
            && $this->due->isDue($contract, $weekStart, (int) ($pending[$contract->id] ?? 0)))->values();
    }

    /**
     * @return list<string>
     */
    private function workingDays(Carbon $weekStart): array
    {
        $days = [];

        foreach ((array) config('green_care.routing.working_days', [1, 2, 3, 4, 5]) as $isoDay) {
            $days[] = $weekStart->copy()->addDays(((int) $isoDay) - 1)->toDateString();
        }

        return $days;
    }

    /**
     * Forecast of each working day at the centre of the sites (or at the depot of the first crew when no site has coordinates).
     *
     * @param  list<string>  $days
     * @param  list<array{key: string, lat: float|null, lng: float|null, minutes: int, activities: list<string>}>  $candidates
     * @param  Collection<int, CrewTeam>  $crews
     * @return array<string, WeatherForecast|null>
     */
    private function forecasts(array $days, array $candidates, Collection $crews): array
    {
        $points = [];

        foreach ($candidates as $candidate) {
            if ($candidate['lat'] !== null && $candidate['lng'] !== null) {
                $points[] = [$candidate['lat'], $candidate['lng']];
            }
        }

        $center = GeoMath::centroid($points);

        if ($center === null) {
            $depot = $crews->first(static fn (CrewTeam $crew): bool => $crew->depot_latitude !== null && $crew->depot_longitude !== null);
            $center = $depot === null ? null : [(float) $depot->depot_latitude, (float) $depot->depot_longitude];
        }

        $forecasts = [];

        foreach ($days as $day) {
            $forecasts[$day] = $center === null ? null : $this->weather->forecast($center[0], $center[1], Carbon::parse($day));
        }

        return $forecasts;
    }

    /**
     * @param  list<string>  $days
     * @param  array<string, WeatherForecast|null>  $forecasts
     * @return list<string>
     */
    private function clearDaysFirst(array $days, array $forecasts): array
    {
        $clear = [];
        $alert = [];

        foreach ($days as $day) {
            if ($this->alerts->evaluate($forecasts[$day] ?? null) === WeatherAlertStatus::Clear) {
                $clear[] = $day;
            } else {
                $alert[] = $day;
            }
        }

        return [...$clear, ...$alert];
    }

    /**
     * @param  Collection<int, CrewTeam>  $crews
     * @return list<array{id: string, depot: array{0: float, 1: float}|null, capacity: int, equipment: array<string, int>, used: array<string, int>}>
     */
    private function crewInputs(Collection $crews, Carbon $weekStart, Carbon $weekEnd): array
    {
        $used = [];

        $existing = CrewRouteSchedule::query()
            ->with('stops')
            ->whereBetween('schedule_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->whereIn('status', [RouteStatus::Planned->value, RouteStatus::InProgress->value])
            ->get();

        foreach ($existing as $schedule) {
            $used[$schedule->crew_team_id][$schedule->schedule_date->toDateString()] = (int) $schedule->stops->sum('estimated_duration_minutes');
        }

        return $crews->map(static fn (CrewTeam $crew): array => [
            'id' => (string) $crew->id,
            'depot' => $crew->depot_latitude !== null && $crew->depot_longitude !== null ? [(float) $crew->depot_latitude, (float) $crew->depot_longitude] : null,
            'capacity' => $crew->daily_capacity_minutes,
            'equipment' => RoutePlanner::normalizeEquipment($crew->equipment),
            'used' => $used[$crew->id] ?? [],
        ])->values()->all();
    }

    /**
     * The planned schedule of the crew on the day (created with the vehicle, trailer and equipment of the crew and the weather outlook).
     *
     * @return array{0: CrewRouteSchedule, 1: bool}
     */
    private function scheduleFor(CrewTeam $crew, string $day, ?WeatherForecast $forecast): array
    {
        $existing = CrewRouteSchedule::query()
            ->where('crew_team_id', $crew->id)
            ->whereDate('schedule_date', $day)
            ->where('status', RouteStatus::Planned->value)
            ->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        $schedule = CrewRouteSchedule::query()->create([
            'tenant_id' => (string) $this->tenants->getTenantId(),
            'schedule_date' => $day,
            'crew_team_id' => $crew->id,
            'assigned_vehicle_plate' => $crew->default_vehicle_plate,
            'assigned_trailer_plate' => $crew->default_trailer_plate,
            'equipment_list' => RoutePlanner::normalizeEquipment($crew->equipment),
            'weather_alert_status' => $this->alerts->evaluate($forecast)->value,
            'weather_forecast_summary' => $forecast?->toArray(),
            'status' => RouteStatus::Planned->value,
        ]);

        return [$schedule, true];
    }
}
