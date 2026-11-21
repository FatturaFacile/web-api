<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Application\Commands;

use Carbon\CarbonImmutable;

/**
 * Moves a scheduled operation to another start (or end) date and/or to an alternative work centre; affected buckets and exceptions are recomputed.
 */
final readonly class RescheduleGanttBlockCommand
{
    public function __construct(
        public string $tenantId,
        public string $blockId,
        public ?CarbonImmutable $startDate = null,
        public ?CarbonImmutable $endDate = null,
        public ?string $workCenterId = null,
    ) {}
}
