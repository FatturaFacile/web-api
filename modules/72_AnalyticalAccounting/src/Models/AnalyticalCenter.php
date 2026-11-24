<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Record of the table analytical_centers.
 *
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 */
final class AnalyticalCenter extends BaseEnterpriseModel
{
    protected $table = 'analytical_centers';

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'valid_from' => 'date', 'valid_to' => 'date', 'metadata' => 'array', 'name_translations' => 'array', 'depth' => 'integer'];
    }

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
}
