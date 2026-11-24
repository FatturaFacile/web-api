<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\MrpPlanning\Infrastructure\Models\MrpActionProposal;

/**
 * @property MrpActionProposal $resource
 */
final class MrpProposalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $proposal = $this->resource;

        return [
            'id' => $proposal->id,
            'mrp_run_id' => $proposal->mrp_run_id,
            'product_id' => $proposal->product_id,
            'mfg_item_id' => $proposal->mfg_item_id,
            'item_code' => $proposal->relationLoaded('item') ? $proposal->item?->item_code : null,
            'item_name' => $proposal->relationLoaded('item') ? $proposal->item?->name : null,
            'bom_version_id' => $proposal->bom_version_id,
            'work_center_id' => $proposal->work_center_id,
            'low_level_code' => $proposal->low_level_code,
            'proposal_type' => $proposal->proposal_type->value,
            'status' => $proposal->status->value,
            'gross_requirement_qty' => $proposal->gross_requirement_qty,
            'projected_on_hand_qty' => $proposal->projected_on_hand_qty,
            'net_requirement_qty' => $proposal->net_requirement_qty,
            'required_qty' => $proposal->required_qty,
            'suggested_order_qty' => $proposal->suggested_order_qty,
            'need_by_date' => $proposal->need_by_date->toDateString(),
            'release_order_by_date' => $proposal->release_order_by_date->toDateString(),
            'planned_start_at' => $proposal->planned_start_at?->toIso8601String(),
            'planned_end_at' => $proposal->planned_end_at?->toIso8601String(),
            'pegged_demand_source_ids' => $proposal->pegged_demand_source_ids ?? [],
            'exception_messages' => $proposal->exception_messages ?? [],
            'supply_reference' => $proposal->supply_reference,
            'firm_reference_type' => $proposal->firm_reference_type,
            'firm_reference_id' => $proposal->firm_reference_id,
            'firmed_at' => $proposal->firmed_at?->toIso8601String(),
            'released_at' => $proposal->released_at?->toIso8601String(),
        ];
    }
}
