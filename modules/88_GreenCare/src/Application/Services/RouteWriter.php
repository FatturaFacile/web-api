<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\GreenCare\Domain\Enums\BillingStatus;
use Modules\GreenCare\Domain\Enums\RouteStatus;
use Modules\GreenCare\Domain\Enums\WeatherAlertStatus;
use Modules\GreenCare\Domain\Exceptions\GreenCareException;
use Modules\GreenCare\Models\CrewRouteSchedule;
use Modules\GreenCare\Models\CrewRouteStop;
use Modules\GreenCare\Models\CrewTeam;
use Modules\GreenCare\Models\GreenSpaceSite;

/**
 * Manual route planning: a route with its stops, and the change of the header, the status and the order of the stops of a route that has not started.
 */
final class RouteWriter
{
    /**
     * @param  array<string, mixed>  $data  validated request
     */
    public function create(array $data, string $tenantId): CrewRouteSchedule
    {
        return DB::transaction(function () use ($data, $tenantId): CrewRouteSchedule {
            $crew = CrewTeam::query()->findOrFail((string) $data['crew_team_id']);
            $schedule = CrewRouteSchedule::query()->create([
                'tenant_id' => $tenantId,
                'schedule_date' => $data['schedule_date'],
                'crew_team_id' => $crew->id,
                'assigned_vehicle_plate' => $data['assigned_vehicle_plate'] ?? $crew->default_vehicle_plate,
                'assigned_trailer_plate' => $data['assigned_trailer_plate'] ?? $crew->default_trailer_plate,
                'equipment_list' => RoutePlanner::normalizeEquipment($data['equipment_list'] ?? $crew->equipment),
                'weather_alert_status' => WeatherAlertStatus::Clear->value,
                'status' => RouteStatus::Planned->value,
            ]);

            foreach (array_values($data['stops'] ?? []) as $index => $stop) {
                $site = GreenSpaceSite::query()->findOrFail((string) $stop['site_id']);
                CrewRouteStop::query()->create([
                    'tenant_id' => $tenantId,
                    'route_schedule_id' => $schedule->id,
                    'site_id' => $site->id,
                    'contract_id' => $stop['contract_id'] ?? null,
                    'stop_order' => $index + 1,
                    'planned_activities' => $stop['planned_activities'] ?? ['mowing'],
                    'estimated_duration_minutes' => (int) ($stop['estimated_duration_minutes'] ?? 60),
                    'counts_toward_contract_passage' => true,
                    'billing_status' => BillingStatus::NotBilled->value,
                ]);
            }

            return $schedule->load(['crew', 'stops.site', 'stops.contract']);
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated request
     *
     * @throws GreenCareException route_locked
     */
    public function update(CrewRouteSchedule $schedule, array $data): CrewRouteSchedule
    {
        if ($schedule->isLocked() && (array_key_exists('stop_order', $data) || array_key_exists('crew_team_id', $data) || array_key_exists('schedule_date', $data))) {
            throw GreenCareException::because('route_locked');
        }

        return DB::transaction(function () use ($schedule, $data): CrewRouteSchedule {
            $schedule->fill(array_intersect_key($data, array_flip(['schedule_date', 'crew_team_id', 'assigned_vehicle_plate', 'assigned_trailer_plate', 'status'])));

            if (array_key_exists('equipment_list', $data)) {
                $schedule->equipment_list = RoutePlanner::normalizeEquipment($data['equipment_list']);
            }

            $schedule->save();

            if (isset($data['stop_order'])) {
                foreach (array_values($data['stop_order']) as $index => $stopId) {
                    CrewRouteStop::query()->where('route_schedule_id', $schedule->id)->whereKey($stopId)->update(['stop_order' => $index + 1]);
                }
            }

            return $schedule->load(['crew', 'stops.site', 'stops.contract']);
        });
    }

    /**
     * @throws GreenCareException route_locked
     */
    public function delete(CrewRouteSchedule $schedule): void
    {
        if ($schedule->isLocked() || CrewRouteStop::query()->where('route_schedule_id', $schedule->id)->whereNotNull('actual_checkout_at')->exists()) {
            throw GreenCareException::because('route_locked');
        }

        DB::transaction(function () use ($schedule): void {
            CrewRouteStop::query()->where('route_schedule_id', $schedule->id)->get()->each->delete();
            $schedule->delete();
        });
    }
}
