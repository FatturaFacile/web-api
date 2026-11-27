<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GreenCare\Application\Services\RoutePlanner;
use Modules\GreenCare\Models\CrewTeam;
use Modules\GreenCare\Models\CrewTeamMember;

/**
 * @mixin CrewTeam
 */
final class CrewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'default_vehicle_plate' => $this->default_vehicle_plate,
            'default_trailer_plate' => $this->default_trailer_plate,
            'equipment' => RoutePlanner::normalizeEquipment($this->equipment),
            'daily_capacity_minutes' => $this->daily_capacity_minutes,
            'depot_latitude' => $this->depot_latitude === null ? null : (float) $this->depot_latitude,
            'depot_longitude' => $this->depot_longitude === null ? null : (float) $this->depot_longitude,
            'is_active' => $this->is_active,
            'members' => $this->members->map(static fn (CrewTeamMember $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'user_id' => $member->user_id,
                'role' => $member->role,
                'hourly_rate' => (float) $member->hourly_rate,
                'licenses' => LicenseResource::collection($member->licenses->each(static fn ($license) => $license->setRelation('operator', $member)))->resolve(),
            ])->values()->all(),
        ];
    }
}
