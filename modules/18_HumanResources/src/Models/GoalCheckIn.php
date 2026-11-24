<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dated update on a goal, or on one of its key results, kept as history.
 */
final class GoalCheckIn extends BaseEnterpriseModel
{
    protected $table = 'hr_goal_check_ins';

    /**
     * @return BelongsTo<Goal, $this>
     */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class, 'goal_id');
    }

    protected function casts(): array
    {
        return ['value' => 'decimal:4', 'progress' => 'decimal:2', 'checked_in_at' => 'datetime'];
    }
}
