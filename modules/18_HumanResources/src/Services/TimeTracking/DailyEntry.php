<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use DateTimeInterface;

/**
 * A daily record of the simplified recording (art. 73b OLL 1), as seen by the engine.
 */
final readonly class DailyEntry
{
    /**
     * @param  string  $workDate  The local day, as Y-m-d.
     */
    public function __construct(
        public string $workDate,
        public int $workedMinutes,
        public ?DateTimeInterface $startTime = null,
        public ?DateTimeInterface $endTime = null,
    ) {}
}
