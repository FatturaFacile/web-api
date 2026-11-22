<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * Available minutes of a work centre per day: a weekly pattern (ISO weekday 1..7 => minutes) overridden by dated
 * exceptions (holidays, extra shifts). Efficiency and availability are already applied by the loader.
 */
final readonly class WorkCenterCalendar
{
    /**
     * @param  array<int, int>  $weeklyMinutes  ISO weekday => minutes
     * @param  array<string, int>  $exceptions  Y-m-d => minutes
     */
    public function __construct(
        public string $workCenterId,
        public array $weeklyMinutes,
        public array $exceptions = [],
        public string $name = '',
    ) {}

    public function availableMinutes(CarbonImmutable $day): int
    {
        return $this->exceptions[$day->toDateString()] ?? $this->weeklyMinutes[$day->dayOfWeekIso] ?? 0;
    }

    public function hasAnyCapacity(): bool
    {
        return array_sum($this->weeklyMinutes) > 0 || array_sum($this->exceptions) > 0;
    }
}
