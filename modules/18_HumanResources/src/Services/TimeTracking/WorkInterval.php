<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use Carbon\CarbonImmutable;

/**
 * A continuous working period between two clock events.
 */
final readonly class WorkInterval
{
    public function __construct(public CarbonImmutable $start, public CarbonImmutable $end) {}

    public function minutes(): int
    {
        return SwissWorkingTimeRules::minutesBetween($this->start, $this->end);
    }
}
