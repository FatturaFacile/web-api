<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

/**
 * A public holiday: the day fraction is the part of the day that is not worked (0.5 for a half day).
 */
final readonly class HolidayEntry
{
    /**
     * @param  string  $date  The local day, as Y-m-d.
     */
    public function __construct(
        public string $date,
        public string $name,
        public float|int|string $dayFraction = 1,
    ) {}
}
