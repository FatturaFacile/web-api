<?php

declare(strict_types=1);

namespace Modules\Integrations\Webhooks;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Builds the JSON document sent to an endpoint, in the payload version the endpoint is pinned to. A breaking change
 * to the document is a new version here and in config('webhooks.payload_versions'); endpoints keep their version until
 * their owner changes it, and the version is sent in the X-FF-Webhook-Version header and in "api_version".
 */
final class WebhookPayloadBuilder
{
    public const string PingEvent = 'webhook.ping';

    /**
     * @param  array<string, mixed>  $data
     * @return array{id: string, type: string, api_version: string, created_at: string, tenant_id: string, data: array<string, mixed>}
     */
    public function build(string $version, string $eventId, string $eventName, string $tenantId, CarbonInterface $occurredAt, array $data): array
    {
        return match ($version) {
            'v1' => [
                'id' => $eventId,
                'type' => $eventName,
                'api_version' => 'v1',
                'created_at' => $occurredAt->toIso8601String(),
                'tenant_id' => $tenantId,
                'data' => $data,
            ],
            default => throw new InvalidArgumentException("Unsupported webhook payload version [{$version}]."),
        };
    }
}
