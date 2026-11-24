<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use Carbon\CarbonImmutable;

/**
 * Outcome of the retention rules for one company.
 */
final readonly class DataRetentionResult
{
    /**
     * @param  CarbonImmutable  $cutoff  Records dated before this day were deleted.
     */
    public function __construct(
        public string $tenantId,
        public CarbonImmutable $cutoff,
        public int $clockEvents,
        public int $dailyRecords,
        public int $auditEntries,
    ) {}

    public function total(): int
    {
        return $this->clockEvents + $this->dailyRecords + $this->auditEntries;
    }
}
