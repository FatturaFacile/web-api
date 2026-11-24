<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\StockPickList;

/**
 * A pick list with its wave, shipment and lines.
 *
 * @mixin StockPickList
 */
final class StockPickListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pick_list_number' => $this->pick_list_number,
            'warehouse_id' => $this->warehouse_id,
            'assigned_picker_user_id' => $this->assigned_picker_user_id,
            'status' => $this->status,
            'origin_document_type' => $this->origin_document_type,
            'origin_document_id' => $this->origin_document_id,
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'shortages' => $this->shortages,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'pick_wave_id' => $this->pick_wave_id,
            'delivery_note_id' => $this->delivery_note_id,
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
