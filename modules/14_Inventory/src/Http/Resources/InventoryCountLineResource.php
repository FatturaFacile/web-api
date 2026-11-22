<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\InventoryCountLine;
use Modules\Inventory\Support\Quantity;

/**
 * A count line. This resource is the only place where a line is serialised for the API, so the blind count rule holds
 * everywhere: a line is shown with the theoretical figures (system quantity, unit cost, system value, variance
 * quantity/value/percentage) only when it is built with `reveal = true`. A blind count sheet (the default) carries what
 * the counter needs and nothing that would bias the count: the item, the bin, the lot, the counted quantity, whether a
 * recount is due and the recount round. The first count of a recounted line is shown only when `revealFirstCount` is set
 * (policy decision of the tenant).
 *
 * @mixin InventoryCountLine
 */
final class InventoryCountLineResource extends JsonResource
{
    public function __construct($resource, private readonly bool $reveal = false, private readonly bool $revealFirstCount = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'inventory_count_session_id' => $this->inventory_count_session_id,
            'warehouse_bin_id' => $this->warehouse_bin_id,
            'bin_barcode' => $this->whenLoaded('bin', fn (): ?string => $this->bin?->barcode),
            'stock_item_id' => $this->stock_item_id,
            'sku' => $this->whenLoaded('item', fn (): ?string => $this->item?->sku),
            'item_name' => $this->whenLoaded('item', fn (): ?string => $this->item?->name),
            'lot_id' => $this->lot_id,
            'counted_quantity' => $this->counted_quantity === null ? null : (string) $this->counted_quantity,
            'is_counted' => $this->counted_quantity !== null,
            'recount_required' => (bool) $this->recount_required,
            'recount_round' => (int) $this->recount_count,
            'recount_reason' => $this->recount_reason,
            'notes' => $this->count_notes,
            'counted_by_user_id' => $this->counted_by_user_id,
            'counted_at' => $this->counted_at?->toIso8601String(),
        ];

        if ($this->revealFirstCount) {
            $data['first_counted_quantity'] = $this->first_counted_quantity === null ? null : (string) $this->first_counted_quantity;
        }

        if (! $this->reveal) {
            return $data;
        }

        return $data + [
            'system_quantity' => (string) $this->system_quantity,
            'unit_cost' => $this->unit_cost === null ? null : (string) $this->unit_cost,
            'system_value' => $this->system_value === null ? null : (string) $this->system_value,
            'first_counted_quantity' => $this->first_counted_quantity === null ? null : (string) $this->first_counted_quantity,
            'final_quantity' => $this->counted_quantity === null ? null : (string) $this->counted_quantity,
            'variance_quantity' => $this->counted_quantity === null ? null : (string) $this->discrepancy_quantity,
            'variance_value' => $this->counted_quantity === null ? null : (string) $this->discrepancy_valuation,
            'variance_percentage' => $this->variance_percentage === null ? null : (string) $this->variance_percentage,
            'variance_percentage_undefined' => $this->counted_quantity !== null && $this->variance_percentage === null && Quantity::isZero((string) $this->system_quantity),
        ];
    }
}
