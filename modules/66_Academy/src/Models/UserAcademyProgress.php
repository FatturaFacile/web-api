<?php

declare(strict_types=1);

namespace Modules\Academy\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The progress of one user on one mission of a track (status started or completed), with the XP and badge it awarded.
 *
 * @property string $user_id
 * @property string $track_id
 * @property string $mission_id
 * @property string $status
 * @property int $xp_awarded
 * @property string|null $badge_code
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
final class UserAcademyProgress extends BaseEnterpriseModel
{
    public const string Started = 'started';

    public const string Completed = 'completed';

    protected $table = 'academy_user_progress';

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<InteractiveMission, $this>
     */
    public function mission(): BelongsTo
    {
        return $this->belongsTo(InteractiveMission::class, 'mission_id');
    }
}
