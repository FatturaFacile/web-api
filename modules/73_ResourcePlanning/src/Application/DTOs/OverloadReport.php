<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\DTOs;

/**
 * The weeks in which an employee is booked above 100 % of the net capacity.
 */
final readonly class OverloadReport
{
    /**
     * @param  list<array{week_start: string, load_percent: float}>  $weeks
     */
    public function __construct(public bool $isOverloaded, public float $peakLoadPercent, public array $weeks) {}

    /**
     * @return array{is_overloaded: bool, peak_load_percent: float, weeks: list<array{week_start: string, load_percent: float}>}
     */
    public function toArray(): array
    {
        return ['is_overloaded' => $this->isOverloaded, 'peak_load_percent' => $this->peakLoadPercent, 'weeks' => $this->weeks];
    }
}
