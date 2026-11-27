<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Distribution\Models\SalesChannelWarehouse;

/**
 * @mixin SalesChannelWarehouse
 */
final class SalesChannelWarehouseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sales_channel_id' => $this->sales_channel_id,
            'warehouse_id' => $this->warehouse_id,
            'allocation_mode' => $this->allocation_mode->value,
            'allocation_percentage' => $this->allocation_percentage,
            'fixed_reserve_qty' => $this->fixed_reserve_qty,
            'priority_rank' => $this->priority_rank,
            'include_in_transit' => $this->include_in_transit,
            'exclude_consignment' => $this->exclude_consignment,
            'exclude_3pl' => $this->exclude_3pl,
            'safety_buffer_qty' => $this->safety_buffer_qty,
            'is_active' => $this->is_active,
            'warehouse' => $this->whenLoaded('warehouse', fn (): array => ['id' => (string) $this->warehouse->id, 'code' => (string) $this->warehouse->code, 'name' => (string) $this->warehouse->name]),
        ];
    }
}
