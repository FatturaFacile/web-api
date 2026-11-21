<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Application\Commands;

use Carbon\CarbonImmutable;
use Modules\MrpPlanning\Domain\Enums\CapacitySchedulingMode;
use Modules\MrpPlanning\Domain\Enums\MrpRunMode;
use Modules\MrpPlanning\Domain\Enums\SchedulingDirection;

/**
 * Runs the MRP engine: low-level codes, gross-to-net explosion, lot sizing, pegging, exceptions and the initial capacity buckets.
 */
final readonly class ExecuteMrpPlanningRunCommand
{
    public function __construct(
        public string $tenantId,
        public int $planningHorizonDays,
        public MrpRunMode $runMode = MrpRunMode::RegenerativeFull,
        public CapacitySchedulingMode $capacitySchedulingMode = CapacitySchedulingMode::InfiniteCapacity,
        public SchedulingDirection $schedulingDirection = SchedulingDirection::BackwardFromDueDate,
        public bool $includeCrossBorderCustomsLeadTime = false,
        public bool $applySupplierShutdownCalendars = false,
        public ?string $userId = null,
        public ?CarbonImmutable $today = null,
    ) {}
}
