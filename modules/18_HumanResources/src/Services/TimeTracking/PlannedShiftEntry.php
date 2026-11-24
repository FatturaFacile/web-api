<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

/**
 * A published shift as seen by the engine: it adds the planned time to a day and never changes what was worked.
 */
final readonly class PlannedShiftEntry
{
    /**
     * @param  string  $workDate  The local day, as Y-m-d.
     */
    public function __construct(
        public string $workDate,
        public string $code,
        public int $plannedMinutes,
    ) {}
}
