<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sustainability\Models\EsgReductionTarget;
use Modules\Sustainability\Services\ReductionTargetService;

/**
 * A reduction target with its progress against the frozen baseline.
 *
 * @mixin EsgReductionTarget
 */
final class ReductionTargetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'scope' => $this->scope,
            'baseline_year' => $this->baseline_year,
            'baseline_co2e_kg' => $this->baseline_co2e_kg,
            'target_year' => $this->target_year,
            'reduction_percent' => $this->reduction_percent,
            'status' => $this->status,
            'notes' => $this->notes,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'evaluated_at' => $this->evaluated_at?->toIso8601String(),
            'progress' => app(ReductionTargetService::class)->progress($this->resource),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
