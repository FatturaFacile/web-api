<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * One operation of a planned production order placed on a work centre; segments are the minutes it loads per day.
 */
final readonly class ScheduleBlock
{
    /**
     * @param  list<array{date: string, minutes: int}>  $segments
     */
    public function __construct(
        public string $id,
        public string $proposalId,
        public string $workCenterId,
        public int $sequence,
        public CarbonImmutable $startDate,
        public CarbonImmutable $endDate,
        public int $minutes,
        public array $segments,
        public bool $isManual = false,
        public ?string $originalWorkCenterId = null,
    ) {}
}
