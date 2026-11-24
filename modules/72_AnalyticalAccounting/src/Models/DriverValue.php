<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Record of the table analytical_driver_values.
 */
final class DriverValue extends BaseEnterpriseModel
{
    protected $table = 'analytical_driver_values';

    protected function casts(): array
    {
        return ['value' => 'decimal:4'];
    }
}
