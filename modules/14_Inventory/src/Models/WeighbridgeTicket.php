<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WeighbridgeTicket extends BaseEnterpriseModel
{
    protected $table = 'weighbridge_tickets';

    protected function casts(): array
    {
        return [
            'first_weight_kg' => 'decimal:3',
            'first_weighed_at' => 'datetime',
            'second_weight_kg' => 'decimal:3',
            'second_weighed_at' => 'datetime',
            'used_stored_tare' => 'boolean',
            'gross_kg' => 'decimal:3',
            'tare_kg' => 'decimal:3',
            'net_kg' => 'decimal:3',
            'density_kg_m3' => 'decimal:3',
            'billed_quantity' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<WeighbridgeVehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(WeighbridgeVehicle::class, 'weighbridge_vehicle_id');
    }
}
