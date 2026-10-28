<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function resource(): BelongsTo
    {
        return $this->belongsTo(ProjectResource::class, 'resource_id');
    }
}
