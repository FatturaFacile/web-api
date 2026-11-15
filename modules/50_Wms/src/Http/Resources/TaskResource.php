<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Wms\Models\WmsTask;

/**
 * An RF task of the warehouse queue (putaway, pick, replenish, count or move) with priority, assignment and confirmation.
 *
 * @mixin WmsTask
 */
final class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'task_type' => $this->task_type,
            'status' => $this->status,
            'priority' => $this->priority,
            'stock_item_id' => $this->stock_item_id,
            'source_bin_id' => $this->source_bin_id,
            'destination_bin_id' => $this->destination_bin_id,
            'planned_quantity' => $this->planned_quantity,
            'confirmed_quantity' => $this->confirmed_quantity,
            'assigned_to' => $this->assigned_to,
            'wave_id' => $this->wave_id,
            'reference' => $this->reference,
            'due_at' => $this->due_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'short_reason' => $this->short_reason,
            'skip_reason' => $this->skip_reason,
            'skip_count' => $this->skip_count,
            'notes' => $this->notes,
            'remaining_quantity' => $this->remainingQuantity(),
            'warehouse' => $this->whenLoaded('warehouse', fn (): ?array => $this->warehouse !== null ? ['id' => $this->warehouse->id, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name] : null),
            'stock_item' => $this->whenLoaded('stockItem', fn (): ?array => $this->stockItem !== null ? ['id' => $this->stockItem->id, 'sku' => $this->stockItem->sku, 'name' => $this->stockItem->name] : null),
            'source_bin' => $this->whenLoaded('sourceBin', fn (): ?array => $this->sourceBin !== null ? ['id' => $this->sourceBin->id, 'code' => $this->sourceBin->code] : null),
            'destination_bin' => $this->whenLoaded('destinationBin', fn (): ?array => $this->destinationBin !== null ? ['id' => $this->destinationBin->id, 'code' => $this->destinationBin->code] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
