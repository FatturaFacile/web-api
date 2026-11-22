<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * An approved leave (Mod 074). A null total means whole days; a total below the capacity of the span is a partial leave
 * (hours off) that is spread over its working days.
 */
final readonly class LeaveSpan
{
    public function __construct(public CarbonImmutable $from, public CarbonImmutable $to, public ?float $totalHours = null) {}

    public function covers(CarbonImmutable $day): bool
    {
        return $day->format('Y-m-d') >= $this->from->format('Y-m-d') && $day->format('Y-m-d') <= $this->to->format('Y-m-d');
    }
}
