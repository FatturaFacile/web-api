<?php

declare(strict_types=1);

namespace Modules\Distribution\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Distribution\Domain\Enums\TransportMode;
use Modules\Inventory\Models\Warehouse;

/**
 * A replenishment lane: the route between two warehouses with its lead time, transport mode and unit cost.
 *
 * @property string $source_warehouse_id
 * @property string $destination_warehouse_id
 * @property int $lead_time_days
 * @property int $lead_time_hours
 * @property TransportMode $transport_mode
 * @property string $cost_per_unit
 * @property bool $is_active
 */
final class DistributionLane extends BaseEnterpriseModel
{
    protected $table = 'distribution_lanes';

    protected function casts(): array
    {
        return [
            'lead_time_days' => 'integer',
            'lead_time_hours' => 'integer',
            'transport_mode' => TransportMode::class,
            'cost_per_unit' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /** Whole days the lane takes (hours round up). */
    public function totalLeadDays(): int
    {
        return $this->lead_time_days + (int) ceil($this->lead_time_hours / 24);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }
}
