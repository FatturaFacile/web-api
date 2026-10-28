<?php

declare(strict_types=1);

namespace Enterprise\Foundation\Concerns;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Services\TransactionalOutboxService;

trait EventAware
{
    /**
     * @var array<int, DomainEventInterface>
     */
    protected array $recordedDomainEvents = [];

    public function recordEvent(DomainEventInterface $event): void
    {
        $this->recordedDomainEvents[] = $event;
    }

    /**
     * @return array<int, DomainEventInterface>
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedDomainEvents;
        $this->recordedDomainEvents = [];

        return $events;
    }

    public function dispatchRecordedEvents(): void
    {
        if (app()->bound(TransactionalOutboxService::class)) {
            $outbox = app(TransactionalOutboxService::class);
            foreach ($this->releaseEvents() as $event) {
                $outbox->record($event);
            }
        }
    }
}
