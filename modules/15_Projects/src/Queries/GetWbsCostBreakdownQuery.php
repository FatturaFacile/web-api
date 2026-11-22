<?php

declare(strict_types=1);

namespace Modules\Projects\Queries;

/**
 * The hierarchical WBS cost breakdown of a project as of its latest snapshot.
 */
final readonly class GetWbsCostBreakdownQuery
{
    public function __construct(public string $projectId) {}
}
