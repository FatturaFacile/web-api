<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GreenCare\Application\Services\RoutePlanner;
use Modules\GreenCare\Models\CrewRouteSchedule;

/**
 * @mixin CrewRouteSchedule
 */
final class RouteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'schedule_date' => $this->schedule_date->format('Y-m-d'),
            'crew_team_id' => $this->crew_team_id,
            'crew_name' => $this->crew->name ?? null,
            'assigned_vehicle_plate' => $this->assigned_vehicle_plate,
            'assigned_trailer_plate' => $this->assigned_trailer_plate,
            'equipment_list' => RoutePlanner::normalizeEquipment($this->equipment_list),
            'weather_alert_status' => $this->weather_alert_status->value,
            'weather_forecast_summary' => $this->weather_forecast_summary,
            'route_optimization_metadata' => $this->route_optimization_metadata,
            'status' => $this->status->value,
            'stops' => StopResource::collection($this->whenLoaded('stops'))->resolve(),
        ];
    }
}
