<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\ValueObjects;

use Carbon\CarbonImmutable;
use Modules\MrpPlanning\Domain\Enums\CapacitySchedulingMode;
use Modules\MrpPlanning\Domain\Enums\MrpRunMode;
use Modules\MrpPlanning\Domain\Enums\SchedulingDirection;

final readonly class RunParameters
{
    public function __construct(
        public CarbonImmutable $today,
        public int $planningHorizonDays,
        public MrpRunMode $mode = MrpRunMode::RegenerativeFull,
        public CapacitySchedulingMode $capacityMode = CapacitySchedulingMode::InfiniteCapacity,
        public SchedulingDirection $direction = SchedulingDirection::BackwardFromDueDate,
        public bool $includeCrossBorderCustomsLeadTime = false,
        public bool $applySupplierShutdownCalendars = false,
        public string $tenantCountry = 'CH',
        public int $defaultCustomsDays = 2,
    ) {}

    public function horizonEnd(): CarbonImmutable
    {
        return $this->today->startOfDay()->addDays($this->planningHorizonDays);
    }

    public function isFinite(): bool
    {
        return $this->capacityMode === CapacitySchedulingMode::FiniteCapacityConstraint;
    }

    public function isBackward(): bool
    {
        return $this->direction === SchedulingDirection::BackwardFromDueDate;
    }
}
