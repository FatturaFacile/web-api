<?php

declare(strict_types=1);

namespace Modules\Planning\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A capacity resource (a work centre, a line, a team) with its default capacity per month. A scenario may override the
 * capacity of a month with a capacity constraint.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $work_center_id
 * @property string $unit
 * @property string $default_capacity
 * @property bool $is_active
 */
final class PlanningResource extends BaseEnterpriseModel
{
    protected $table = 'planning_resources';

    protected function casts(): array
    {
        return [
            'default_capacity' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PlanningItemResourceRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(PlanningItemResourceRate::class, 'resource_id');
    }
}
