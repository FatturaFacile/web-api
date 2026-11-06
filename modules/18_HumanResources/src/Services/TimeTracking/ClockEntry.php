<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use DateTimeInterface;

/**
 * A clock event as seen by the engine.
 */
final readonly class ClockEntry
{
    public function __construct(
        public DateTimeInterface $occurredAt,
        public TimeEventType $eventType,
        public bool $isDeleted = false,
    ) {}
}
