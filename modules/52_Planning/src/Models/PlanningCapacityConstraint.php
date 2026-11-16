<?php

declare(strict_types=1);

namespace Modules\Planning\Models;

use App\Support\Database\Concerns\ProtectsRecords;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The capacity of a resource in a month of a scenario, overriding the default capacity of the resource.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $scenario_id
 * @property string $resource_id
 * @property Carbon $period
 * @property string $available_capacity
 * @property string|null $notes
 */
final class PlanningCapacityConstraint extends BaseEnterpriseModel
{
    use ProtectsRecords;

    protected $table = 'planning_capacity_constraints';

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'available_capacity' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<PlanningResource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(PlanningResource::class, 'resource_id');
    }

    protected function recordProtectionViolation(string $operation): ?string
    {
        $status = $this->scenario_id === null ? null : PlanningScenario::query()->whereKey($this->scenario_id)->value('status');

        return $status !== null && $status !== PlanningScenario::Draft ? __('planning_errors.scenario.not_draft') : null;
    }
}
