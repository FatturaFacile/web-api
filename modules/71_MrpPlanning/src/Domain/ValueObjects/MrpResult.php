<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\ValueObjects;

/**
 * Output of the calculation: proposals, the schedule blocks of the new production proposals and the capacity buckets
 * (which also count the fixed blocks).
 */
final readonly class MrpResult
{
    /**
     * @param  list<PlannedProposal>  $proposals
     * @param  list<ScheduleBlock>  $blocks
     * @param  list<array{work_center_id: string, bucket_date: string, available_minutes: int, loaded_minutes: int}>  $buckets
     * @param  array<string, int>  $lowLevelCodes
     */
    public function __construct(
        public array $proposals,
        public array $blocks,
        public array $buckets,
        public array $lowLevelCodes,
    ) {}
}
