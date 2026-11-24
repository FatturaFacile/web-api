<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgQualityCharacteristic;
use Modules\Manufacturing\Models\MfgQualityPlan;

/**
 * An inspection plan with its characteristics (when loaded).
 *
 * @mixin MfgQualityPlan
 */
final class MfgQualityPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan_code' => $this->plan_code,
            'name' => $this->name,
            'inspection_type' => $this->inspection_type,
            'mfg_item_id' => $this->mfg_item_id,
            'description' => $this->description,
            'acceptance_defect_limit' => $this->acceptance_defect_limit,
            'is_active' => $this->is_active,
            'characteristics' => $this->whenLoaded('characteristics', fn () => $this->characteristics->map(static fn (MfgQualityCharacteristic $characteristic): array => [
                'id' => $characteristic->id,
                'characteristic_name' => $characteristic->characteristic_name,
                'target_value' => $characteristic->target_value,
                'min_tolerance' => $characteristic->min_tolerance,
                'max_tolerance' => $characteristic->max_tolerance,
                'uom' => $characteristic->uom,
                'inspection_method' => $characteristic->inspection_method,
                'is_critical' => $characteristic->is_critical,
            ])->values()->all()),
        ];
    }
}
