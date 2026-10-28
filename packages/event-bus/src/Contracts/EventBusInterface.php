<?php

declare(strict_types=1);

namespace Enterprise\EventBus\Contracts;

interface EventBusInterface
{
    public function dispatch(DomainEventInterface $event): void;

    public function publish(DomainEventInterface $event): void;
}
