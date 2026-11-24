<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sustainability\Models\EsgKpiDefinition;

/**
 * An ESG indicator definition.
 *
 * @mixin EsgKpiDefinition
 */
final class KpiDefinitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'pillar' => $this->pillar,
            'unit' => $this->unit,
            'direction' => $this->direction,
            'esrs_reference' => $this->esrs_reference,
            'target_value' => $this->target_value,
            'is_example' => $this->is_example,
            'is_active' => $this->is_active,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
