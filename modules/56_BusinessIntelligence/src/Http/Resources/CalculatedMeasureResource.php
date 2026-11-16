<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\BusinessIntelligence\Models\BiCalculatedMeasure;

/**
 * A calculated measure of a dataset.
 *
 * @mixin BiCalculatedMeasure
 */
final class CalculatedMeasureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dataset' => $this->dataset,
            'key' => $this->key,
            'label' => $this->label,
            'formula' => $this->formula,
            'unit' => $this->unit,
            'scale' => $this->scale,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
