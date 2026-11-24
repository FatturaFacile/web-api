<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The weekly working capacity of a resource, against which the planned load of its task assignments is compared.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $resource_id
 * @property string $weekly_capacity_hours
 * @property string $maximum_overtime_hours
 * @property string $efficiency_factor
 */
final class ResourceCapacityProfile extends BaseEnterpriseModel
{
    protected $table = 'resource_capacity_profiles';

    protected function casts(): array
    {
        return [
            'weekly_capacity_hours' => 'decimal:2',
            'maximum_overtime_hours' => 'decimal:2',
            'efficiency_factor' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<ProjectResource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(ProjectResource::class, 'resource_id');
    }
}
