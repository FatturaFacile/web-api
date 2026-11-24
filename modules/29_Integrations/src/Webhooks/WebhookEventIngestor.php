<?php

declare(strict_types=1);

namespace Modules\Integrations\Webhooks;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Integrations\Jobs\DeliverWebhookJob;
use Modules\Integrations\Models\WebhookDelivery;
use Modules\Integrations\Models\WebhookEndpoint;
use Throwable;

/**
 * Turns a domain event into one delivery per endpoint that subscribed to it. Isolation between tenants lives here:
 * the endpoints are looked up by the tenant id of the EVENT, never by the tenant of the request or the queue worker
 * that happens to run the listener, and an event without a valid tenant id reaches nobody.
 *
 * It runs inside the transaction of the code that raised the event (for events dispatched in-process): the deliveries
 * are written with it and the jobs are queued after commit, so a rolled-back business operation sends nothing. A
 * failure here never breaks that operation: it is logged and the event is simply not delivered.
 */
final class WebhookEventIngestor
{
    /**
     * The same outbox event can be dispatched twice by the relay (at-least-once); an identical payload for the same
     * endpoint within this many minutes counts as that repeat. Only used for events that come from the outbox.
     */
    private const int RepeatWindowMinutes = 60;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly WebhookPayloadBuilder $payloads,
    ) {}

    /**
     * An event object dispatched in-process (channel "class").
     */
    public function fromEvent(DomainEventInterface $event): int
    {
        return $this->ingest($event->getEventName(), $event->getTenantId(), $event->toPayload(), false);
    }

    /**
     * An event the outbox relay dispatches by name, with its array payload (channel "outbox").
     *
     * @param  array<string, mixed>|DomainEventInterface  $payload
     */
    public function fromOutbox(string $eventName, array|DomainEventInterface $payload): int
    {
        if ($payload instanceof DomainEventInterface) {
            return $this->ingest($eventName, $payload->getTenantId(), $payload->toPayload(), true);
        }

        $tenantId = (string) ($payload['tenantId'] ?? $payload['tenant_id'] ?? '');

        return $this->ingest($eventName, $tenantId, $payload, true);
    }

    /**
     * @param  array<string, mixed>  $eventPayload
     */
    private function ingest(string $eventName, string $tenantId, array $eventPayload, bool $checkRepeat): int
    {
        if (! WebhookEventCatalog::has($eventName) || ! Str::isUuid($tenantId)) {
            return 0;
        }

        try {
            return DB::transaction(fn (): int => $this->createDeliveries($eventName, $tenantId, $eventPayload, $checkRepeat));
        } catch (Throwable $exception) {
            Log::error('Webhook deliveries could not be created for an event.', ['event' => $eventName, 'tenant_id' => $tenantId, 'exception' => $exception->getMessage()]);

            return 0;
        }
    }

    /**
     * @param  array<string, mixed>  $eventPayload
     */
    private function createDeliveries(string $eventName, string $tenantId, array $eventPayload, bool $checkRepeat): int
    {
        $endpoints = WebhookEndpoint::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', WebhookEndpoint::StatusActive)
            ->whereJsonContains('events', $eventName)
            ->get();

        $tenant = $endpoints->isEmpty() ? null : Tenant::find($tenantId);

        if ($tenant === null) {
            return 0;
        }

        $data = WebhookEventCatalog::dataFrom($eventPayload);
        $eventId = (string) Str::uuid7();
        $occurredAt = isset($eventPayload['occurredAt']) && is_string($eventPayload['occurredAt']) && $eventPayload['occurredAt'] !== '' ? Carbon::parse($eventPayload['occurredAt']) : now();
        $hash = hash('sha256', $eventName.'|'.$tenantId.'|'.json_encode($data));
        $created = 0;

        $this->tenantContext->runWithTenant($tenant, function () use ($endpoints, $eventName, $tenantId, $eventId, $occurredAt, $data, $hash, $checkRepeat, &$created): void {
            foreach ($endpoints as $endpoint) {
                if ($checkRepeat && WebhookDelivery::withoutGlobalScopes()->where('webhook_endpoint_id', $endpoint->id)->where('dedupe_hash', $hash)->where('created_at', '>=', now()->subMinutes(self::RepeatWindowMinutes))->exists()) {
                    continue;
                }

                $delivery = WebhookDelivery::create([
                    'tenant_id' => $tenantId,
                    'webhook_endpoint_id' => $endpoint->id,
                    'event_id' => $eventId,
                    'event_name' => $eventName,
                    'idempotency_key' => (string) Str::uuid7(),
                    'status' => WebhookDelivery::StatusPending,
                    'next_attempt_at' => now(),
                    'payload' => $this->payloads->build((string) $endpoint->payload_version, $eventId, $eventName, $tenantId, $occurredAt, $data),
                    'payload_version' => $endpoint->payload_version,
                    'is_test' => false,
                    'dedupe_hash' => $checkRepeat ? $hash : null,
                ]);

                DeliverWebhookJob::dispatch((string) $delivery->id);
                $created++;
            }
        });

        return $created;
    }
}
