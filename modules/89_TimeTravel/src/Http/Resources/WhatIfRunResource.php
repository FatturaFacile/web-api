<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TimeTravel\Models\WhatIfSimulationRun;
use Modules\TimeTravel\Support\Instant;

/**
 * @mixin WhatIfSimulationRun
 */
final class WhatIfRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'simulation_name' => $this->simulation_name,
            'valuation_method' => $this->valuation_method,
            'target_valid_at' => Instant::iso(Instant::from((string) $this->target_valid_at)),
            'target_known_at' => Instant::iso(Instant::from((string) $this->target_known_at)),
            'baseline_summary' => $this->baseline_summary,
            'simulated_summary' => $this->simulated_summary,
            'delta_summary' => $this->delta_summary,
            'items' => $this->items ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
