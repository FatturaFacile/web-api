<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Integrations\Models\WebhookDelivery;
use Modules\Integrations\Models\WebhookEndpoint;

/**
 * @extends Factory<WebhookDelivery>
 */
final class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $eventId = (string) Str::uuid7();

        return [
            'tenant_id' => Tenant::factory(),
            'webhook_endpoint_id' => WebhookEndpoint::factory(),
            'event_id' => $eventId,
            'event_name' => 'invoicing.invoice.posted',
            'idempotency_key' => (string) Str::uuid7(),
            'status' => WebhookDelivery::StatusPending,
            'attempts' => 0,
            'next_attempt_at' => now(),
            'payload' => ['id' => $eventId, 'type' => 'invoicing.invoice.posted', 'api_version' => 'v1', 'data' => []],
            'payload_version' => 'v1',
            'is_test' => false,
        ];
    }
}
