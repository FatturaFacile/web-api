<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sustainability\Models\EsgEmissionFactor;

/**
 * An emission factor of one version.
 *
 * @mixin EsgEmissionFactor
 */
final class EmissionFactorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'version' => $this->version,
            'name' => $this->name,
            'activity_type' => $this->activity_type,
            'unit' => $this->unit,
            'scope' => $this->scope,
            'category' => $this->category,
            'kg_co2e_per_unit' => $this->kg_co2e_per_unit,
            'region' => $this->region,
            'source' => $this->source,
            'source_year' => $this->source_year,
            'is_example' => $this->is_example,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
