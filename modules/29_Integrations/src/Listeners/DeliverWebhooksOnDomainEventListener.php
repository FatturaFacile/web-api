<?php

declare(strict_types=1);

namespace Modules\Integrations\Listeners;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Modules\Integrations\Webhooks\WebhookEventIngestor;

/**
 * Hooks the webhook catalogue into the event bus, next to the DispatchOutboxOn...Listener classes of the modules:
 * events dispatched in-process arrive through handle(), events that only travel through the transactional outbox
 * arrive, once the relay dispatches them, through handleOutboxEvent() (see WebhookEventCatalog for the channel of each).
 */
final readonly class DeliverWebhooksOnDomainEventListener
{
    public function __construct(private WebhookEventIngestor $ingestor) {}

    public function handle(DomainEventInterface $event): void
    {
        $this->ingestor->fromEvent($event);
    }

    /**
     * @param  array<string, mixed>|DomainEventInterface  $payload
     */
    public function handleOutboxEvent(string $eventName, array|DomainEventInterface $payload): void
    {
        $this->ingestor->fromOutbox($eventName, $payload);
    }
}
