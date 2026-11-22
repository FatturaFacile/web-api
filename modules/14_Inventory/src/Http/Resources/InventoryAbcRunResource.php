<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\InventoryAbcRun;
use Modules\Inventory\Models\InventoryAbcRunItem;

/**
 * An ABC run: its parameters, class counts and, when loaded, the ranked items (the historical snapshot).
 *
 * @mixin InventoryAbcRun
 */
final class InventoryAbcRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'period_days' => (int) $this->period_days,
            'a_share' => (string) $this->a_share,
            'b_share' => (string) $this->b_share,
            'period_start' => $this->period_start->toIso8601String(),
            'period_end' => $this->period_end->toIso8601String(),
            'total_consumption_value' => (string) $this->total_consumption_value,
            'item_count' => (int) $this->item_count,
            'class_a_count' => (int) $this->class_a_count,
            'class_b_count' => (int) $this->class_b_count,
            'class_c_count' => (int) $this->class_c_count,
            'applied' => (bool) $this->applied,
            'run_by' => $this->run_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn (): array => $this->items
                ->sortBy('rank')
                ->map(static fn (InventoryAbcRunItem $item): array => [
                    'stock_item_id' => $item->stock_item_id,
                    'sku' => $item->item?->sku,
                    'name' => $item->item?->name,
                    'rank' => (int) $item->rank,
                    'consumption_value' => (string) $item->consumption_value,
                    'cumulative_share' => (string) $item->cumulative_share,
                    'abc_class' => $item->abc_class,
                    'previous_class' => $item->previous_class,
                    'is_manual_override' => (bool) $item->is_manual_override,
                    'is_new_item' => (bool) $item->is_new_item,
                ])
                ->values()
                ->all()),
        ];
    }
}
