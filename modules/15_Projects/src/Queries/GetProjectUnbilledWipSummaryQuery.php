<?php

declare(strict_types=1);

namespace Modules\Projects\Queries;

/**
 * What a project has earned but not billed: unbilled time and materials, completed milestones and open SAL certificates, retention held and the latest WIP valuation (Mod 081).
 */
final readonly class GetProjectUnbilledWipSummaryQuery
{
    public function __construct(
        public string $projectId,
    ) {}
}
