<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Handlers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\GreenCare\Application\Commands\RescheduleRoutesOnWeatherAlertCommand;
use Modules\GreenCare\Application\Services\RoutePlanner;
use Modules\GreenCare\Domain\Enums\RouteStatus;
use Modules\GreenCare\Domain\Enums\WeatherAlertStatus;
use Modules\GreenCare\Domain\Weather\IWeatherForecastProvider;
use Modules\GreenCare\Domain\Weather\WeatherAlertEvaluator;
use Modules\GreenCare\Domain\Weather\WeatherForecast;
use Modules\GreenCare\Events\RoutesRescheduledDueToWeather;
use Modules\GreenCare\Models\CrewRouteSchedule;
use Modules\GreenCare\Models\CrewRouteStop;

/**
 * Auto-reschedule on weather alert: the planned routes of the period get a fresh forecast; when rain or frost hits a route, its outdoor stops
 * (mowing, spraying) move to the next clear working day of the same crew that has the capacity, and the routes are flagged
 * (rain warning / frost alert, or rescheduled due to weather when nothing is left on the day). Routes that already started are never touched.
 */
final class RescheduleRoutesOnWeatherAlertHandler
{
    public function __construct(
        private readonly IWeatherForecastProvider $weather,
        private readonly WeatherAlertEvaluator $alerts,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @return array{rescheduled_stops: int, affected_routes: Collection<int, CrewRouteSchedule>, routes: Collection<int, CrewRouteSchedule>}
     */
    public function handle(RescheduleRoutesOnWeatherAlertCommand $command): array
    {
        return DB::transaction(function () use ($command): array {
            $schedules = CrewRouteSchedule::query()
                ->with(['crew', 'stops.site'])
                ->whereBetween('schedule_date', [$command->from->toDateString(), $command->to->toDateString()])
                ->where('status', RouteStatus::Planned->value)
                ->orderBy('schedule_date')
                ->get();

            $touched = [];
            $affected = [];
            $moved = [];

            foreach ($schedules as $schedule) {
                $forecast = $this->forecastOf($schedule, $schedule->schedule_date);
                $status = $this->alerts->evaluate($forecast);

                if ($forecast !== null) {
                    $schedule->weather_forecast_summary = $forecast->toArray();
                }

                $schedule->weather_alert_status = $status;
                $schedule->save();
                $touched[$schedule->id] = true;

                if ($status === WeatherAlertStatus::Clear) {
                    continue;
                }

                $affectedStops = $schedule->stops->filter(fn (CrewRouteStop $stop): bool => ! $stop->isCompleted()
                    && $this->alerts->blocksActivities($status, $stop->planned_activities ?? []));

                if ($affectedStops->isEmpty()) {
                    continue;
                }

                $affected[$schedule->id] = true;
                $target = $this->targetDay($schedule, $affectedStops);

                if ($target === null) {
                    continue;
                }

                $targetSchedule = $this->scheduleOn($schedule, $target['date'], $target['forecast']);
                $touched[$targetSchedule->id] = true;
                $order = (int) CrewRouteStop::query()->where('route_schedule_id', $targetSchedule->id)->max('stop_order');

                foreach ($affectedStops as $stop) {
                    $stop->route_schedule_id = $targetSchedule->id;
                    $stop->stop_order = ++$order;
                    $stop->save();
                    $moved[] = (string) $stop->id;
                }

                $this->closeGaps($schedule);

                if (CrewRouteStop::query()->where('route_schedule_id', $schedule->id)->doesntExist()) {
                    $schedule->status = RouteStatus::Postponed;
                    $schedule->weather_alert_status = WeatherAlertStatus::RescheduledDueToWeather;
                    $schedule->save();
                }
            }

            if ($moved !== [] || $affected !== []) {
                event(new RoutesRescheduledDueToWeather((string) $this->tenants->getTenantId(), array_keys($affected), $moved));
            }

            $load = static fn (array $ids): Collection => CrewRouteSchedule::query()
                ->with(['crew', 'stops.site', 'stops.contract'])
                ->whereKey($ids)
                ->orderBy('schedule_date')
                ->get();

            return [
                'rescheduled_stops' => count($moved),
                'affected_routes' => $load(array_keys($affected)),
                'routes' => $load(array_keys($touched)),
            ];
        });
    }

    /**
     * The first clear working day after the route where the crew has the capacity for the moved stops.
     *
     * @param  Collection<int, CrewRouteStop>  $stops
     * @return array{date: Carbon, forecast: WeatherForecast|null}|null
     */
    private function targetDay(CrewRouteSchedule $schedule, Collection $stops): ?array
    {
        $workingDays = array_map('intval', (array) config('green_care.routing.working_days', [1, 2, 3, 4, 5]));
        $needed = (int) $stops->sum('estimated_duration_minutes');

        for ($offset = 1; $offset <= (int) config('green_care.routing.reschedule_horizon_days', 7); $offset++) {
            $date = $schedule->schedule_date->copy()->addDays($offset);

            if (! in_array($date->dayOfWeekIso, $workingDays, true)) {
                continue;
            }

            $forecast = $this->forecastOf($schedule, $date);

            if ($this->alerts->evaluate($forecast) !== WeatherAlertStatus::Clear) {
                continue;
            }

            $existing = CrewRouteSchedule::query()
                ->with('stops')
                ->where('crew_team_id', $schedule->crew_team_id)
                ->whereDate('schedule_date', $date->toDateString())
                ->where('status', RouteStatus::Planned->value)
                ->first();

            $used = $existing === null ? 0 : (int) $existing->stops->sum('estimated_duration_minutes');

            if ($used + $needed <= $schedule->crew->daily_capacity_minutes) {
                return ['date' => $date, 'forecast' => $forecast];
            }
        }

        return null;
    }

    private function forecastOf(CrewRouteSchedule $schedule, Carbon $date): ?WeatherForecast
    {
        $site = $schedule->stops->map(static fn (CrewRouteStop $stop) => $stop->site)->first(static fn ($site): bool => $site !== null && $site->hasCoordinates());

        if ($site !== null) {
            return $this->weather->forecast((float) $site->latitude, (float) $site->longitude, $date);
        }

        $crew = $schedule->crew;

        return $crew->depot_latitude !== null && $crew->depot_longitude !== null
            ? $this->weather->forecast((float) $crew->depot_latitude, (float) $crew->depot_longitude, $date)
            : null;
    }

    private function scheduleOn(CrewRouteSchedule $source, Carbon $date, ?WeatherForecast $forecast): CrewRouteSchedule
    {
        $existing = CrewRouteSchedule::query()
            ->where('crew_team_id', $source->crew_team_id)
            ->whereDate('schedule_date', $date->toDateString())
            ->where('status', RouteStatus::Planned->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return CrewRouteSchedule::query()->create([
            'tenant_id' => (string) $this->tenants->getTenantId(),
            'schedule_date' => $date->toDateString(),
            'crew_team_id' => $source->crew_team_id,
            'assigned_vehicle_plate' => $source->assigned_vehicle_plate,
            'assigned_trailer_plate' => $source->assigned_trailer_plate,
            'equipment_list' => $source->equipment_list ?? RoutePlanner::normalizeEquipment($source->crew->equipment),
            'weather_alert_status' => WeatherAlertStatus::Clear->value,
            'weather_forecast_summary' => $forecast?->toArray(),
            'status' => RouteStatus::Planned->value,
        ]);
    }

    private function closeGaps(CrewRouteSchedule $schedule): void
    {
        $order = 0;

        foreach (CrewRouteStop::query()->where('route_schedule_id', $schedule->id)->orderBy('stop_order')->get() as $stop) {
            $stop->stop_order = ++$order;
            $stop->save();
        }
    }
}
