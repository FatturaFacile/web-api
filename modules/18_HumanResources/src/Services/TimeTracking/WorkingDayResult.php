<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use Carbon\CarbonImmutable;

/**
 * The evaluation of one day of an employee.
 */
final class WorkingDayResult
{
    public ?CarbonImmutable $firstIn = null;

    public ?CarbonImmutable $lastOut = null;

    public int $workedMinutes = 0;

    /** Interruptions of at least 15 minutes. */
    public int $breakMinutes = 0;

    /** Contract hours on working days, without holidays. */
    public int $targetMinutes = 0;

    /** Minutes credited by paid absences. */
    public int $creditedMinutes = 0;

    /** Night work minutes (23:00 - 06:00). */
    public int $nightMinutes = 0;

    /** Sunday work (Saturday 23:00 - Sunday 23:00). */
    public bool $sundayWork = false;

    /** The rest since the previous working period. */
    public ?int $restBeforeMinutes = null;

    public ?string $absence = null;

    /** @var array<int, string> */
    public array $issues = [];

    public function __construct(public CarbonImmutable $date) {}

    /**
     * The daily balance (worked + credited - target).
     */
    public function balanceMinutes(): int
    {
        return $this->workedMinutes + $this->creditedMinutes - $this->targetMinutes;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date->format('Y-m-d'),
            'first_in' => $this->firstIn?->toIso8601String(),
            'last_out' => $this->lastOut?->toIso8601String(),
            'worked_minutes' => $this->workedMinutes,
            'break_minutes' => $this->breakMinutes,
            'target_minutes' => $this->targetMinutes,
            'credited_minutes' => $this->creditedMinutes,
            'balance_minutes' => $this->balanceMinutes(),
            'night_minutes' => $this->nightMinutes,
            'sunday_work' => $this->sundayWork,
            'rest_before_minutes' => $this->restBeforeMinutes,
            'absence' => $this->absence,
            'issues' => $this->issues,
        ];
    }
}
