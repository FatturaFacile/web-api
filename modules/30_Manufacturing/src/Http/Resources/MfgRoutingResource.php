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
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
