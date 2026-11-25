<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\LiquidityPlanning\Models\LiquidityScenario;

/**
 * A saved what-if scenario.
 *
 * @mixin LiquidityScenario
 */
final class ScenarioResource extends JsonResource
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
            'levers' => $this->leversObject()->toArray(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
