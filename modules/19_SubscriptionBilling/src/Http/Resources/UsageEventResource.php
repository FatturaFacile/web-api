<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SubscriptionBilling\Models\UsageEvent;

/**
 * A metered usage record.
 *
 * @mixin UsageEvent
 */
final class UsageEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subscription_id' => $this->subscription_id,
            'subscription_item_id' => $this->subscription_item_id,
            'metric_code' => $this->metric_code,
            'idempotency_key' => $this->idempotency_key,
            'quantity' => (string) $this->getRawOriginal('quantity'),
            'event_timestamp' => $this->event_timestamp?->toIso8601String(),
            'is_processed' => (bool) $this->is_processed,
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
