<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CreditManagement\Models\CreditDunningStrategy;

/**
 * A dunning strategy with its ordered steps.
 *
 * @mixin CreditDunningStrategy
 */
final class DunningStrategyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'steps' => DunningStepResource::collection($this->whenLoaded('steps')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
