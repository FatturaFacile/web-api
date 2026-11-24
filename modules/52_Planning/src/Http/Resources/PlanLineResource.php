<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Planning\Models\PlanningPlanLine;

/**
 * A demand or supply line of a scenario.
 *
 * @mixin PlanningPlanLine
 */
final class PlanLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scenario_id' => $this->scenario_id,
            'line_type' => $this->line_type,
            'item_id' => $this->item_id,
            'item_code' => $this->item_code,
            'item_name' => $this->item_name,
            'period' => $this->period->toDateString(),
            'quantity' => $this->quantity,
            'source' => $this->source,
            'notes' => $this->notes,
        ];
    }
}
