<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\Subcontracting\Domain\Enums\DeadlineStatus;

/**
 * Art. 53 DPR 633/72 and DPR 441/1997: goods sent for processing without transfer of ownership must come back within one
 * year of the DDT, or the transfer is presumed to be a sale. The deadline is the DDT date plus one year; it warns 30 days
 * before.
 */
final class StatutoryDeadline
{
    public const int WarningDays = 30;

    public static function for(DateTimeInterface $ddtDate): CarbonImmutable
    {
        return CarbonImmutable::instance($ddtDate)->startOfDay()->addYearNoOverflow();
    }

    public static function status(?DateTimeInterface $deadline, string $remainingQty, ?DateTimeInterface $today = null): DeadlineStatus
    {
        if ($deadline === null || bccomp($remainingQty, '0', 4) <= 0) {
            return DeadlineStatus::Closed;
        }

        $days = self::daysLeft($deadline, $today);

        return match (true) {
            $days < 0 => DeadlineStatus::Expired,
            $days <= self::WarningDays => DeadlineStatus::Warning,
            default => DeadlineStatus::Ok,
        };
    }

    /** Negative once the deadline is over. */
    public static function daysLeft(DateTimeInterface $deadline, ?DateTimeInterface $today = null): int
    {
        $now = $today !== null ? CarbonImmutable::instance($today) : CarbonImmutable::today();

        return (int) $now->startOfDay()->diffInDays(CarbonImmutable::instance($deadline)->startOfDay(), false);
    }
}
