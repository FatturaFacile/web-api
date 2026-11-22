<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Application\Queries;

/**
 * A run with its live KPIs.
 */
final readonly class GetMrpRunSummaryQuery
{
    public function __construct(
        public string $tenantId,
        public string $runId,
    ) {}
}
