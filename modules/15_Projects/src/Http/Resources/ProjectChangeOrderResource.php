<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectChangeOrder;

/**
 * API representation of a change order (schema ProjectsChangeOrder).
 *
 * @mixin ProjectChangeOrder
 */
final class ProjectChangeOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectChangeOrder $order */
        $order = $this->resource;

        return [
            'id' => $order->id,
            'project_id' => $order->project_id,
            'order_number' => $order->order_number,
            'title' => $order->title,
            'scope_description' => $order->scope_description,
            'cost_impact_amount' => (string) $order->cost_impact_amount,
            'revenue_impact_amount' => (string) $order->revenue_impact_amount,
            'schedule_impact_days' => $order->schedule_impact_days,
            'status' => $order->status,
            'approved_at' => $order->approved_by_client_at?->toIso8601String(),
            'rejection_reason' => $order->rejection_reason,
            'lines' => $this->whenLoaded('lines'),
        ];
    }
}
