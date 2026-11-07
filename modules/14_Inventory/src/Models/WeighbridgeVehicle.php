<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The card of a vehicle that crosses the weighbridge, with the tare stored for quick weighings.
 */
final class WeighbridgeVehicle extends BaseEnterpriseModel
{
    protected $table = 'weighbridge_vehicles';

    protected function casts(): array
    {
        return [
            'stored_tare_kg' => 'decimal:3',
            'tare_recorded_on' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
