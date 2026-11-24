<?php

declare(strict_types=1);

namespace Enterprise\EventBus\Contracts;

interface DomainEventInterface
{
    public function getEventName(): string;

    public function getAggregateType(): string;

    public function getAggregateId(): string;

    public function getTenantId(): string;

    public function getCompanyId(): string;

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array;
}
