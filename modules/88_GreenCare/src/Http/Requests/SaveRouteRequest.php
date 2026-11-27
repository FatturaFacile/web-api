<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\GreenCare\Domain\Enums\RouteStatus;
use Modules\GreenCare\Domain\Enums\ServiceType;

/**
 * Manual route (create) or change of the header, the status and the order of the stops (update).
 */
final class SaveRouteRequest extends GreenCareRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'schedule_date' => [$creating ? 'required' : 'sometimes', 'date'],
            'crew_team_id' => [$creating ? 'required' : 'sometimes', 'uuid', $this->inTenant('crew_teams')],
            'assigned_vehicle_plate' => ['nullable', 'string', 'max:20'],
            'assigned_trailer_plate' => ['nullable', 'string', 'max:20'],
            'equipment_list' => ['nullable', 'array'],
            'status' => ['sometimes', Rule::in(RouteStatus::values())],
            'stops' => [$creating ? 'sometimes' : 'prohibited', 'array', 'max:100'],
            'stops.*.site_id' => ['required', 'uuid', $this->inTenant('green_space_sites')],
            'stops.*.contract_id' => ['nullable', 'uuid', $this->inTenant('seasonal_maintenance_contracts')],
            'stops.*.planned_activities' => ['nullable', 'array'],
            'stops.*.planned_activities.*' => ['string', Rule::in(array_map(static fn (ServiceType $type): string => $type->value, ServiceType::cases()))],
            'stops.*.estimated_duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'stop_order' => ['sometimes', 'array', 'max:100'],
            'stop_order.*' => ['uuid', $this->inTenant('crew_route_stops')],
        ];
    }
}
