<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgOperation;
use Modules\Manufacturing\Models\MfgRouting;

/**
 * A routing with its operations (when loaded). Times are minutes as decimal strings.
 *
 * @mixin MfgRouting
 */
final class MfgRoutingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'routing_number' => $this->routing_number,
            'mfg_item_id' => $this->mfg_item_id,
            'item_code' => $this->whenLoaded('mfgItem', fn () => $this->mfgItem?->item_code),
            'item_name' => $this->whenLoaded('mfgItem', fn () => $this->mfgItem?->name),
            'name' => $this->name,
            'revision' => $this->revision,
            'status' => $this->status,
            'is_default' => $this->is_default,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'notes' => $this->notes,
            'bom_id' => $this->bom_id,
            'operations_count' => $this->whenCounted('operations'),
            'operations' => $this->whenLoaded('operations', fn () => $this->operations->map(static fn (MfgOperation $operation): array => [
                'id' => $operation->id,
                'sequence' => $operation->sequence,
                'operation_code' => $operation->operation_code,
                'name' => $operation->name,
                'work_center_id' => $operation->work_center_id,
                'machine_center_id' => $operation->machine_center_id,
                'setup_time_minutes' => $operation->setup_time_minutes,
                'run_time_minutes_per_unit' => $operation->run_time_minutes_per_unit,
                'labor_time_minutes_per_unit' => $operation->labor_time_minutes_per_unit,
                'is_subcontracted' => $operation->is_subcontracted,
                'subcontractor_contact_id' => $operation->subcontractor_contact_id,
                'subcontract_unit_cost' => $operation->subcontract_unit_cost,
                'quality_plan_id' => $operation->quality_plan_id,
                'operation_type' => $operation->operation_type,
                'queue_time_minutes' => $operation->queue_time_minutes,
                'post_operation_wait_minutes' => $operation->post_operation_wait_minutes,
                'move_time_minutes' => $operation->move_time_minutes,
                'overlap_transfer_batch_qty' => $operation->overlap_transfer_batch_qty,
                'required_operators' => $operation->required_operators,
                'alternate_work_center_ids' => $operation->alternate_work_center_ids ?? [],
                'linked_quality_control_point_id' => $operation->linked_quality_control_point_id,
                'work_instruction_doc_id' => $operation->work_instruction_doc_id,
                'bom_components' => $operation->relationLoaded('bomComponents') ? $operation->bomComponents->map(static fn ($link): array => ['bom_component_id' => $link->bom_component_id, 'backflush_on_completion' => $link->backflush_on_completion, 'notes' => $link->notes])->values()->all() : [],
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
