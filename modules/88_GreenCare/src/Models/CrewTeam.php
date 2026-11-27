<?php

declare(strict_types=1);

namespace Modules\GreenCare\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A crew with its default vehicle, trailer and equipment.
 *
 * @property string $code
 * @property string $name
 * @property string|null $default_vehicle_plate
 * @property string|null $default_trailer_plate
 * @property array<string, int>|null $equipment equipment type => units
 * @property int $daily_capacity_minutes
 * @property string|null $depot_latitude
 * @property string|null $depot_longitude
 * @property bool $is_active
 */
final class CrewTeam extends BaseEnterpriseModel
{
    protected $table = 'crew_teams';

    protected function casts(): array
    {
        return [
            'equipment' => 'array',
            'daily_capacity_minutes' => 'integer',
            'depot_latitude' => 'decimal:7',
            'depot_longitude' => 'decimal:7',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CrewTeamMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(CrewTeamMember::class, 'crew_team_id');
    }
}
