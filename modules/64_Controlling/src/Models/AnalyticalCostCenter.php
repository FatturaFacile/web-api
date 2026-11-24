<?php

declare(strict_types=1);

namespace Modules\Controlling\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Controlling record of the table analytical_cost_centers.
 *
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $center_type
 * @property string|null $parent_id
 */
final class AnalyticalCostCenter extends BaseEnterpriseModel
{
    protected $table = 'analytical_cost_centers';

    public const array Types = ['production', 'commercial', 'administrative', 'auxiliary_service', 'profit_center'];

    /** Centers that pass their cost on to the direct centers. */
    public const array IndirectTypes = ['auxiliary_service', 'administrative'];

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'valid_from' => 'date', 'valid_to' => 'date'];
    }
}
