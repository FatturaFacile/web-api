<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Services;

use Carbon\CarbonImmutable;
use Modules\MrpPlanning\Domain\ValueObjects\ShutdownPeriod;

/**
 * Date arithmetic for order lead times. Weekends never carry a release date; supplier shutdowns (Ferragosto, Swiss
 * factory holidays, public holidays) neither carry one nor count as lead-time days, because the supplier does not work
 * on them. Cross-border customs clearance days are added by the caller as extra calendar days.
 */
final class PlanningCalendar
{
    private const int GuardDays = 3650;

    /**
     * @param  list<ShutdownPeriod>  $shutdowns
     */
    public function __construct(private readonly array $shutdowns = [], private readonly bool $applyShutdowns = false) {}

    public function isShutdown(CarbonImmutable $day, ?string $supplierId): bool
    {
        if (! $this->applyShutdowns) {
            return false;
        }
        foreach ($this->shutdowns as $period) {
            if ($period->covers($day, $supplierId)) {
                return true;
            }
        }

        return false;
    }

    public function isWorkingDay(CarbonImmutable $day, ?string $supplierId): bool
    {
        return ! $day->isWeekend() && ! $this->isShutdown($day, $supplierId);
    }

    /**
     * The release date that lies $days lead-time days before the due date.
     *
     * @return array{date: CarbonImmutable, shifted: bool} shifted: a shutdown was skipped or the date moved because of one
     */
    public function subtractLeadTime(CarbonImmutable $due, int $days, ?string $supplierId): array
    {
        $day = $due->startOfDay();
        $shifted = false;
        $remaining = max(0, $days);

        for ($guard = 0; $remaining > 0 && $guard < self::GuardDays; $guard++) {
            $day = $day->subDay();
            if ($this->isShutdown($day, $supplierId)) {
                $shifted = true;

                continue;
            }
            $remaining--;
        }

        for ($guard = 0; ! $this->isWorkingDay($day, $supplierId) && $guard < self::GuardDays; $guard++) {
            $shifted = $shifted || $this->isShutdown($day, $supplierId);
            $day = $day->subDay();
        }

        return ['date' => $day, 'shifted' => $shifted];
    }

    /**
     * The date at which an order released on $start arrives after $days lead-time days.
     *
     * @return array{date: CarbonImmutable, shifted: bool}
     */
    public function addLeadTime(CarbonImmutable $start, int $days, ?string $supplierId): array
    {
        $day = $start->startOfDay();
        $shifted = false;
        $remaining = max(0, $days);

        for ($guard = 0; $remaining > 0 && $guard < self::GuardDays; $guard++) {
            $day = $day->addDay();
            if ($this->isShutdown($day, $supplierId)) {
                $shifted = true;

                continue;
            }
            $remaining--;
        }

        return ['date' => $day, 'shifted' => $shifted];
    }

    /**
     * The first working day on or after the date.
     */
    public function nextWorkingDay(CarbonImmutable $date, ?string $supplierId): CarbonImmutable
    {
        $day = $date->startOfDay();
        for ($guard = 0; ! $this->isWorkingDay($day, $supplierId) && $guard < self::GuardDays; $guard++) {
            $day = $day->addDay();
        }

        return $day;
    }
}
