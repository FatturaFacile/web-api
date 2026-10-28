<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectChangeOrder;

/**
 * @mixin ProjectChangeOrder
 */
final class ProjectChangeOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'order_number' => $this->order_number,
            'title' => $this->title,
            'scope_description' => $this->scope_description,
            'cost_impact_amount' => (float) $this->cost_impact_amount,
            'revenue_impact_amount' => (float) $this->revenue_impact_amount,
            'schedule_impact_days' => $this->schedule_impact_days,
            'status' => $this->status,
            'approved_by_client_at' => $this->approved_by_client_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines'),
        ];
    }
}
