<?php

declare(strict_types=1);

namespace Modules\Integrations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Integrations\Models\WebhookDelivery;

/**
 * @mixin WebhookDelivery
 */
final class WebhookDeliveryResource extends JsonResource
{
    /**
     * @param  bool  $detailed  adds the payload and the log of attempts
     */
    public function __construct($resource, private readonly bool $detailed = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'webhook_endpoint_id' => $this->webhook_endpoint_id,
            'event_id' => $this->event_id,
            'event_name' => $this->event_name,
            'idempotency_key' => $this->idempotency_key,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'next_attempt_at' => $this->next_attempt_at,
            'is_test' => $this->is_test,
            'last_response_code' => $this->last_response_code,
            'last_error' => $this->last_error,
            'last_duration_ms' => $this->last_duration_ms,
            'delivered_at' => $this->delivered_at,
            'created_at' => $this->created_at,
            $this->mergeWhen($this->detailed, fn (): array => [
                'payload' => $this->payload,
                'attempt_log' => $this->attemptLog->map(fn ($attempt): array => $attempt->only(['attempt_number', 'succeeded', 'response_code', 'duration_ms', 'error', 'response_excerpt', 'created_at']))->all(),
            ]),
        ];
    }
}
