<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\GreenCare\Models\CrewTeam;
use Modules\GreenCare\Models\CrewTeamMember;

/**
 * Saves a crew with its members: members sent with an id are updated, new ones are created and those left out are removed (their
 * licenses and history stay on file).
 */
final class CrewWriter
{
    /**
     * @param  array<string, mixed>  $data  validated request
     */
    public function save(?CrewTeam $crew, array $data, string $tenantId): CrewTeam
    {
        return DB::transaction(function () use ($crew, $data, $tenantId): CrewTeam {
            $attributes = [
                'code' => $data['code'],
                'name' => $data['name'],
                'default_vehicle_plate' => $data['default_vehicle_plate'] ?? null,
                'default_trailer_plate' => $data['default_trailer_plate'] ?? null,
                'equipment' => RoutePlanner::normalizeEquipment($data['equipment'] ?? null),
                'daily_capacity_minutes' => (int) ($data['daily_capacity_minutes'] ?? 480),
                'depot_latitude' => $data['depot_latitude'] ?? null,
                'depot_longitude' => $data['depot_longitude'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ];

            if ($crew === null) {
                $crew = CrewTeam::query()->create($attributes + ['tenant_id' => $tenantId]);
            } else {
                $crew->update($attributes);
            }

            if (array_key_exists('members', $data)) {
                $this->syncMembers($crew, $data['members'], $tenantId);
            }

            return $crew->load('members.licenses');
        });
    }

    /**
     * @param  list<array<string, mixed>>  $members
     */
    private function syncMembers(CrewTeam $crew, array $members, string $tenantId): void
    {
        $keep = [];

        foreach ($members as $row) {
            $attributes = [
                'name' => $row['name'],
                'user_id' => $row['user_id'] ?? null,
                'role' => $row['role'] ?? null,
                'hourly_rate' => $row['hourly_rate'] ?? 0,
            ];
            $member = ! empty($row['id']) ? CrewTeamMember::query()->where('crew_team_id', $crew->id)->find($row['id']) : null;

            if ($member === null) {
                $member = CrewTeamMember::query()->create($attributes + ['tenant_id' => $tenantId, 'crew_team_id' => $crew->id]);
            } else {
                $member->update($attributes);
            }

            $keep[] = (string) $member->id;
        }

        CrewTeamMember::query()->where('crew_team_id', $crew->id)->whereNotIn('id', $keep)->get()->each->delete();
    }
}
