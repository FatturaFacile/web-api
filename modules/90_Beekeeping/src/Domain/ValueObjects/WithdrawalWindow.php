<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Domain\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Withdrawal period (tempo di sospensione) of a veterinary treatment: from the treatment date up to, but excluding, the end date.
 * Honey supers cannot be placed nor honey harvested while a date falls inside the window.
 */
final readonly class WithdrawalWindow
{
    public CarbonImmutable $start;

    public CarbonImmutable $end;

    public function __construct(CarbonImmutable $treatmentDate, public int $days)
    {
        if ($days < 0) {
            throw new InvalidArgumentException('The withdrawal period cannot be negative.');
        }

        $this->start = $treatmentDate->startOfDay();
        $this->end = $this->start->addDays($days);
    }

    public function covers(CarbonImmutable $date): bool
    {
        $day = $date->startOfDay();

        return $day->greaterThanOrEqualTo($this->start) && $day->lessThan($this->end);
    }

    public function daysLeft(CarbonImmutable $today): int
    {
        return max(0, (int) $today->startOfDay()->diffInDays($this->end, false));
    }
}
