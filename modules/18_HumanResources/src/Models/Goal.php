<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A goal of the company, of a team or of an employee. A goal may cascade from a parent goal of a higher level, and its
 * progress comes from its key results.
 *
 * @property string $level
 * @property string $status
 * @property string $goal_cycle_id
 * @property string|null $parent_goal_id
 * @property string|null $employee_id
 * @property string|null $team_id
 * @property float|string $weight
 * @property float|string $progress
 * @property Carbon|null $due_on
 */
final class Goal extends BaseEnterpriseModel
{
    public const string LevelCompany = 'company';

    public const string LevelTeam = 'team';

    public const string LevelEmployee = 'employee';

    public const array Levels = [self::LevelCompany, self::LevelTeam, self::LevelEmployee];

    public const string StatusDraft = 'draft';

    public const string StatusActive = 'active';

    public const string StatusCompleted = 'completed';

    public const string StatusCancelled = 'cancelled';

    public const array Statuses = [self::StatusDraft, self::StatusActive, self::StatusCompleted, self::StatusCancelled];

    protected $table = 'hr_goals';

    /**
     * @return BelongsTo<GoalCycle, $this>
     */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(GoalCycle::class, 'goal_cycle_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_goal_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_goal_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    /**
     * @return HasMany<GoalKeyResult, $this>
     */
    public function keyResults(): HasMany
    {
        return $this->hasMany(GoalKeyResult::class, 'goal_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<GoalCheckIn, $this>
     */
    public function checkIns(): HasMany
    {
        return $this->hasMany(GoalCheckIn::class, 'goal_id')->orderByDesc('checked_in_at');
    }

    protected function casts(): array
    {
        return ['due_on' => 'date:Y-m-d', 'weight' => 'decimal:2', 'progress' => 'decimal:2'];
    }
}
