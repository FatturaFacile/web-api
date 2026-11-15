<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A measurable result of a goal: it moves from a start value to a target value.
 *
 * @property float|string $start_value
 * @property float|string $target_value
 * @property float|string $current_value
 * @property float|string $progress
 */
final class GoalKeyResult extends BaseEnterpriseModel
{
    protected $table = 'hr_goal_key_results';

    /**
     * @return BelongsTo<Goal, $this>
     */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class, 'goal_id');
    }

    protected function casts(): array
    {
        return [
            'start_value' => 'decimal:4',
            'target_value' => 'decimal:4',
            'current_value' => 'decimal:4',
            'progress' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }
}
