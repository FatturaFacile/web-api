<?php

declare(strict_types=1);

namespace Modules\Plm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Plm\Models\PlmItemRevision;

/**
 * A revision of a manufacturing item with PLM Mod 168 lifecycle, eBOM/mBOM, CAD files, and compliance metadata.
 *
 * @mixin PlmItemRevision
 */
final class RevisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mfg_item_id' => $this->mfg_item_id,
            'item' => $this->whenLoaded('item', fn (): ?array => $this->item === null ? null : ['id' => $this->item->id, 'item_code' => $this->item->item_code, 'name' => $this->item->name]),
            'revision' => $this->revision,
            'status' => $this->status,
            'lifecycle_state' => $this->lifecycle_state ?? $this->lifecycleStateEnum()->value,
            'parent_revision_id' => $this->parent_revision_id,
            'engineering_part_number' => $this->engineering_part_number,
            'description' => $this->description,
            'ebom_structure_json' => $this->ebom_structure_json,
            'mbom_id' => $this->mbom_id,
            'routing_id' => $this->routing_id,
            'cad_drawing_pdf_and_step_file_ids' => $this->cad_drawing_pdf_and_step_file_ids ?? [],
            'rohs_and_reach_compliant' => (bool) $this->rohs_and_reach_compliant,
            'conflict_minerals_compliant' => (bool) $this->conflict_minerals_compliant,
            'technical_file_ref' => $this->technical_file_ref,
            'regulatory_metadata_json' => $this->regulatory_metadata_json,
            'mes_drawing_obsolete_watermark' => $this->isObsoleteForMes(),
            'superseded_by_revision_id' => $this->superseded_by_revision_id,
            'change_summary' => $this->change_summary,
            'change_order_id' => $this->change_order_id,
            'effective_from' => $this->effective_from?->toDateString(),
            'released_at' => $this->released_at?->toIso8601String(),
            'obsoleted_at' => $this->obsoleted_at?->toIso8601String(),
        ];
    }
}
