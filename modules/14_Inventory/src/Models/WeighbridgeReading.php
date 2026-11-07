<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A value read from a weighbridge device, kept as it arrived.
 */
final class WeighbridgeReading extends BaseEnterpriseModel
{
    protected $table = 'weighbridge_readings';

    protected function casts(): array
    {
        return [
            'weight_kg' => 'decimal:3',
            'is_stable' => 'boolean',
            'read_at' => 'datetime',
        ];
    }
}
