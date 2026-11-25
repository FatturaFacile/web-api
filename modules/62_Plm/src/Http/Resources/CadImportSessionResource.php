<?php

declare(strict_types=1);

namespace Modules\Plm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Plm\Models\PlmCadImportSession;

/**
 * @mixin PlmCadImportSession
 */
final class CadImportSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'session_code' => $this->session_code,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn (): ?array => $this->product === null ? null : [
                'id' => $this->product->id,
                'item_code' => $this->product->item_code,
                'name' => $this->product->name,
            ]),
            'target_revision_id' => $this->target_revision_id,
            'target_mbom_id' => $this->target_mbom_id,
            'cad_source_system' => $this->cad_source_system,
            'source_file_metadata' => $this->source_file_metadata,
            'parsed_part_list_json' => $this->parsed_part_list_json,
            'new_parts_to_create_count' => $this->new_parts_to_create_count,
            'created_parts_json' => $this->created_parts_json ?? [],
            'changed_quantities_diff_list' => $this->changed_quantities_diff_list ?? [],
            'removed_parts_list' => $this->removed_parts_list ?? [],
            'side_by_side_diff_json' => $this->side_by_side_diff_json,
            'cost_rollup_comparison_json' => $this->cost_rollup_comparison_json,
            'validation_warnings_json' => $this->validation_warnings_json ?? [],
            'status' => $this->status,
            'committed_at' => $this->committed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
