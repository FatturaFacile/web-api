<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Wms\Models\WmsCrossDockOrder;

/**
 * Goods that go from an inbound receipt to an outbound shipment without being stored. Lines carry the quantity received and the quantity matched to the outbound side.
 *
 * @mixin WmsCrossDockOrder
 */
final class CrossDockOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'number' => $this->number,
            'inbound_reference' => $this->inbound_reference,
            'outbound_reference' => $this->outbound_reference,
            'inbound_door_id' => $this->inbound_door_id,
            'outbound_door_id' => $this->outbound_door_id,
            'status' => $this->status,
            'planned_inbound_at' => $this->planned_inbound_at?->toIso8601String(),
            'planned_outbound_at' => $this->planned_outbound_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'notes' => $this->notes,
            'warehouse' => $this->whenLoaded('warehouse', fn (): ?array => $this->warehouse !== null ? ['id' => $this->warehouse->id, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name] : null),
            'lines' => CrossDockLineResource::collection($this->whenLoaded('lines')),
            'match_status' => $this->matchStatus(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
