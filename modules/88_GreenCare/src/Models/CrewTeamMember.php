<?php

declare(strict_types=1);

namespace Modules\GreenCare\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An operator of a crew.
 *
 * @property string $crew_team_id
 * @property string|null $user_id
 * @property string $name
 * @property string|null $role
 * @property string $hourly_rate
 */
final class CrewTeamMember extends BaseEnterpriseModel
{
    protected $table = 'crew_team_members';

    protected function casts(): array
    {
        return ['hourly_rate' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<CrewTeam, $this>
     */
    public function crew(): BelongsTo
    {
        return $this->belongsTo(CrewTeam::class, 'crew_team_id');
    }

    /**
     * @return HasMany<OperatorPhytosanitaryLicense, $this>
     */
    public function licenses(): HasMany
    {
        return $this->hasMany(OperatorPhytosanitaryLicense::class, 'crew_team_member_id');
    }
}
