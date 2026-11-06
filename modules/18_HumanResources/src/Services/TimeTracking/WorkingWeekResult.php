<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use Carbon\CarbonImmutable;

/**
 * The evaluation of one calendar week (Monday to Sunday) inside the period.
 */
final readonly class WorkingWeekResult
{
    /**
     * @param  int  $statutoryOvertimeMinutes  The work beyond the legal maximum (lavoro straordinario, art. 12 LL).
     */
    public function __construct(
        public CarbonImmutable $weekStart,
        public int $workedMinutes,
        public int $legalMaximumMinutes,
        public int $statutoryOvertimeMinutes,
    ) {}

    /**
     * @return array{week_start: string, worked_minutes: int, legal_maximum_minutes: int, statutory_overtime_minutes: int}
     */
    public function toArray(): array
    {
        return [
            'week_start' => $this->weekStart->format('Y-m-d'),
            'worked_minutes' => $this->workedMinutes,
            'legal_maximum_minutes' => $this->legalMaximumMinutes,
            'statutory_overtime_minutes' => $this->statutoryOvertimeMinutes,
        ];
    }
}
