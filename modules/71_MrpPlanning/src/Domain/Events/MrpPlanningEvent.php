<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Events;

use Modules\Manufacturing\Events\BaseManufacturingEvent;

/**
 * Integration event of the MRP module for the outbox: a run finished or proposals were released. The payload is a plain
 * array of scalars so that the offline-first clients (WPF, Flutter) can consume it as-is.
 */
final class MrpPlanningEvent extends BaseManufacturingEvent
{
    public const string RunCompleted = 'mrp.run.completed';

    public const string ProposalsReleased = 'mrp.proposals.released';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly string $eventName,
        private readonly string $aggregateType,
        private readonly string $aggregateId,
        private readonly array $payload,
        string $tenantId,
    ) {
        parent::__construct($tenantId);
    }

    public function getAggregateType(): string
    {
        return $this->aggregateType;
    }

    public function getAggregateId(): string
    {
        return $this->aggregateId;
    }

    public function getEventName(): string
    {
        return $this->eventName;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return $this->payload;
    }
}
