<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectBillingEvent;

/**
 * API representation of a billing run of a project (schema ProjectsBillingEvent).
 *
 * @mixin ProjectBillingEvent
 */
final class ProjectBillingEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectBillingEvent $event */
        $event = $this->resource;

        return [
            'id' => $event->id,
            'event_type' => $event->event_type,
            'source_reference_id' => $event->source_reference_id,
            'billing_amount' => (string) $event->billing_amount,
            'status' => $event->status,
            'invoice_id' => $event->invoice_id,
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }
}
