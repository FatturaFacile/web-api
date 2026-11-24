<?php

declare(strict_types=1);

namespace Modules\Planning\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The capacity an item consumes on a resource per unit supplied (hours per piece, for example).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $resource_id
 * @property string $item_code
 * @property string $load_per_unit
 */
final class PlanningItemResourceRate extends BaseEnterpriseModel
{
    protected $table = 'planning_item_resource_rates';

    protected function casts(): array
    {
        return ['load_per_unit' => 'decimal:5'];
    }

    /**
     * @return BelongsTo<PlanningResource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(PlanningResource::class, 'resource_id');
    }
}
