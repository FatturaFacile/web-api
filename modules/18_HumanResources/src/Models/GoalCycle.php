<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A period in which goals are set and followed (for example a quarter or a year).
 *
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property Carbon|null $closed_at
 * @property string $status
 */
final class GoalCycle extends BaseEnterpriseModel
{
    public const string StatusDraft = 'draft';

    public const string StatusActive = 'active';

    public const string StatusClosed = 'closed';

    public const array Statuses = [self::StatusDraft, self::StatusActive, self::StatusClosed];

    protected $table = 'hr_goal_cycles';

    /**
     * @return HasMany<Goal, $this>
     */
    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class, 'goal_cycle_id');
    }

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'closed_at' => 'datetime'];
    }
}
