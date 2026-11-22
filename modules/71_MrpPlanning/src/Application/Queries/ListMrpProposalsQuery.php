<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Application\Queries;

use Carbon\CarbonImmutable;

/**
 * Filtered, sorted page of the proposals of a run (the latest completed run when none is given).
 */
final readonly class ListMrpProposalsQuery
{
    public function __construct(
        public string $tenantId,
        public ?string $runId = null,
        public ?string $proposalType = null,
        public ?string $status = null,
        public ?string $exceptionCode = null,
        public ?string $itemId = null,
        public ?string $workCenterId = null,
        public ?CarbonImmutable $needByFrom = null,
        public ?CarbonImmutable $needByTo = null,
        public string $sortBy = 'need_by_date',
        public string $sortDirection = 'asc',
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}
