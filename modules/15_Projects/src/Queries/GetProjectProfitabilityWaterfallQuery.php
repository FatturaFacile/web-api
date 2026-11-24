<?php

declare(strict_types=1);

namespace Modules\Projects\Queries;

/**
 * Revenue, cost components and gross margin of a project, with the budget-to-forecast variance bridge.
 */
final readonly class GetProjectProfitabilityWaterfallQuery
{
    public function __construct(public string $projectId) {}
}
