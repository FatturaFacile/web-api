<?php

declare(strict_types=1);

namespace Modules\Projects\Commands;

/**
 * Allocates an overhead pool of a cost centre (Mod 018) to projects by a driver (labor_hours, direct_cost or
 * custom_weights).
 */
final readonly class AllocateOverheadToProjectsCommand
{
    /**
     * @param  array<string, string|int|float>  $weights  custom weight by project id (custom_weights driver only)
     * @param  list<string>  $projectIds  the projects of the run; empty for every open project
     */
    public function __construct(
        public string $runKey,
        public string $costCenterId,
        public string $poolAmount,
        public string $periodStart,
        public string $periodEnd,
        public string $driver,
        public array $weights = [],
        public array $projectIds = [],
    ) {}
}
