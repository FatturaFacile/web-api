<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Record of the table analytical_allocation_drivers.
 */
final class AllocationDriver extends BaseEnterpriseModel
{
    protected $table = 'analytical_allocation_drivers';

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_active' => 'boolean'];
    }

    /**
     * @return HasMany<DriverValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(DriverValue::class, 'allocation_driver_id');
    }
}
