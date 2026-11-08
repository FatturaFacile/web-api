<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgBomComponent;

/**
 * A BOM revision with its effective range, approval and components (when loaded). Quantities are decimal strings.
 *
 * @mixin MfgBom
 */
final class MfgBomResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bom_number' => $this->bom_number,
            'mfg_item_id' => $this->mfg_item_id,
            'name' => $this->name,
            'revision' => $this->revision,
            'version' => $this->version,
            'bom_type' => $this->bom_type,
            'status' => $this->status,
            'base_quantity' => $this->base_quantity,
            'uom' => $this->uom,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'supersedes_bom_id' => $this->supersedes_bom_id,
            'engineering_change_order_id' => $this->engineering_change_order_id,
            'notes' => $this->notes,
            'components' => $this->whenLoaded('components', fn () => $this->components->map(static fn (MfgBomComponent $component): array => [
                'id' => $component->id,
                'component_item_id' => $component->component_item_id,
                'component_item_code' => $component->componentItem?->item_code,
                'sequence' => $component->sequence,
                'quantity_per' => $component->quantity_per,
                'uom' => $component->uom,
                'scrap_percentage' => $component->scrap_percentage,
                'yield_percentage' => $component->yield_percentage,
                'is_phantom' => $component->is_phantom,
                'issue_method' => $component->issue_method,
                'operation_sequence' => $component->operation_sequence,
                'notes' => $component->notes,
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
