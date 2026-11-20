<?php

declare(strict_types=1);

namespace Modules\Academy\Domain\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;

/**
 * Base of the Academy domain events.
 */
abstract readonly class AcademyDomainEvent implements DomainEventInterface
{
    public const string Prefix = 'academy.';

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $tenantId,
        public string $userId,
        public string $aggregateId,
        public array $data = [],
    ) {}

    abstract public function getEventName(): string;

    abstract public function getAggregateType(): string;

    public function getAggregateId(): string
    {
        return $this->aggregateId;
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->tenantId;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return ['user_id' => $this->userId] + $this->data;
    }
}
