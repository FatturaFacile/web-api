<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Application\Commands;

use Modules\MrpPlanning\Domain\Enums\SchedulingDirection;

/**
 * Re-levels the proposed production orders of a run against the finite capacity of the work centres (alternatives included); firm orders and manual placements stay.
 */
final readonly class AutoLevelFiniteCapacityScheduleCommand
{
    public function __construct(
        public string $tenantId,
        public string $runId,
        public ?SchedulingDirection $direction = null,
    ) {}
}
