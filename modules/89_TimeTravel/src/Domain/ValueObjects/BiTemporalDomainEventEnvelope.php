<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Domain\ValueObjects;

use Carbon\CarbonImmutable;
use Modules\TimeTravel\Domain\Enums\AggregateType;

/**
 * What a domain write hands to the event store: the recorder adds the sequence, the recording time and the hash chain.
 */
final readonly class BiTemporalDomainEventEnvelope
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $tenantId,
        public AggregateType $aggregateType,
        public string $aggregateId,
        public string $eventType,
        public CarbonImmutable $validAt,
        public array $payload,
        public array $metadata = [],
        public ?string $actorUserId = null,
    ) {}
}
