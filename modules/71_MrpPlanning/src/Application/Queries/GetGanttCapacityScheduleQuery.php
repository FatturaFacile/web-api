<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Application\Queries;

use Carbon\CarbonImmutable;

/**
 * Work centres, capacity buckets and scheduled blocks of a run between two dates.
 */
final readonly class GetGanttCapacityScheduleQuery
{
    /**
     * @param  list<string>  $workCenterIds
     */
    public function __construct(
        public string $tenantId,
        public string $runId,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public array $workCenterIds = [],
    ) {}
}
