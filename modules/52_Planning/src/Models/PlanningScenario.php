<?php

declare(strict_types=1);

namespace Modules\Planning\Models;

use App\Support\Database\Concerns\ProtectsRecords;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A planning scenario: the baseline or a what-if copy of it. It runs through the S&OP cycle draft, review, approved and
 * locked; only a draft takes changes, and a locked scenario is immutable except for the publication stamp.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $description
 * @property string $scenario_type
 * @property string|null $base_scenario_id
 * @property string $status
 * @property Carbon $horizon_start
 * @property Carbon $horizon_end
 * @property string|null $submitted_by
 * @property Carbon|null $submitted_at
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $locked_by
 * @property Carbon|null $locked_at
 * @property int $published_version
 * @property Carbon|null $published_at
 * @property string|null $published_by
 */
final class PlanningScenario extends BaseEnterpriseModel
{
    use ProtectsRecords;

    public const string Baseline = 'baseline';

    public const string WhatIf = 'what_if';

    public const array Types = [self::Baseline, self::WhatIf];

    public const string Draft = 'draft';

    public const string Review = 'review';

    public const string Approved = 'approved';

    public const string Locked = 'locked';

    public const array Statuses = [self::Draft, self::Review, self::Approved, self::Locked];

    protected $table = 'planning_scenarios';

    protected function casts(): array
    {
        return [
            'horizon_start' => 'date',
            'horizon_end' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'locked_at' => 'datetime',
            'published_at' => 'datetime',
            'published_version' => 'integer',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === self::Draft;
    }

    /**
     * @return HasMany<PlanningPlanLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PlanningPlanLine::class, 'scenario_id');
    }

    /**
     * @return HasMany<PlanningApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(PlanningApproval::class, 'scenario_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<PlanningCapacityConstraint, $this>
     */
    public function constraints(): HasMany
    {
        return $this->hasMany(PlanningCapacityConstraint::class, 'scenario_id');
    }

    protected function recordProtectionViolation(string $operation): ?string
    {
        if ($operation !== 'update' || $this->getOriginal('status') !== self::Locked) {
            return null;
        }

        return $this->changesOutside(['published_version', 'published_at', 'published_by', 'updated_at'])
            ? __('planning_errors.scenario.locked')
            : null;
    }
}
