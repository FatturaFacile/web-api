<?php

declare(strict_types=1);

namespace Modules\Distribution\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Distribution\Domain\Enums\AllocationMode;
use Modules\Inventory\Models\Warehouse;

/**
 * How a sales channel draws on a warehouse: allocation mode, ring-fenced quantity, in-transit inclusion, consignment and 3PL
 * exclusion and the safety buffer.
 *
 * @property string $sales_channel_id
 * @property string $warehouse_id
 * @property AllocationMode $allocation_mode
 * @property string|null $allocation_percentage
 * @property string $fixed_reserve_qty
 * @property int $priority_rank
 * @property bool $include_in_transit
 * @property bool $exclude_consignment
 * @property bool $exclude_3pl
 * @property string $safety_buffer_qty
 * @property bool $is_active
 */
final class SalesChannelWarehouse extends BaseEnterpriseModel
{
    protected $table = 'distribution_sales_channel_warehouses';

    protected function casts(): array
    {
        return [
            'allocation_mode' => AllocationMode::class,
            'allocation_percentage' => 'decimal:3',
            'fixed_reserve_qty' => 'decimal:4',
            'priority_rank' => 'integer',
            'include_in_transit' => 'boolean',
            'exclude_consignment' => 'boolean',
            'exclude_3pl' => 'boolean',
            'safety_buffer_qty' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SalesChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class, 'sales_channel_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
