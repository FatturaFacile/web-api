<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TalentLearning\Models\CompCycle;

/**
 * A compensation cycle.
 *
 * @mixin CompCycle
 */
final class CompCycleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'fiscal_year' => $this->fiscal_year,
            'currency' => $this->currency,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'effective_on' => $this->effective_on->toDateString(),
            'status' => $this->status,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'notes' => $this->notes,
            'pools' => $this->whenLoaded('pools', fn () => BudgetPoolResource::collection($this->pools)),
            'guidelines' => $this->whenLoaded('guidelines', fn (): array => $this->guidelines->map(static fn ($guideline): array => [
                'performance_rating' => $guideline->performance_rating,
                'min_percent' => $guideline->min_percent,
                'max_percent' => $guideline->max_percent,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
