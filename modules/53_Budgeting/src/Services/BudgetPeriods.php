<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Illuminate\Support\Carbon;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * The twelve fiscal periods of a version: period 1 is the month of the start of its fiscal year.
 */
final class BudgetPeriods
{
    public const int Count = 12;

    public static function periodStart(BudgetVersion $version, int $period): Carbon
    {
        return Carbon::parse($version->year_start)->startOfMonth()->addMonthsNoOverflow($period - 1)->startOfDay();
    }

    public static function periodEnd(BudgetVersion $version, int $period): Carbon
    {
        return self::periodStart($version, $period)->endOfMonth()->startOfDay();
    }

    /**
     * The fiscal period a calendar month (Y-m) falls in, or null outside the fiscal year.
     */
    public static function periodOfMonth(BudgetVersion $version, string $month): ?int
    {
        $start = Carbon::parse($version->year_start)->startOfMonth();
        $target = Carbon::parse($month.'-01')->startOfMonth();
        $index = (int) round($start->diffInMonths($target, false)) + 1;

        return $index >= 1 && $index <= self::Count ? $index : null;
    }

    /**
     * The last fiscal period that has started on the given day (0 before the fiscal year starts, 12 after it ends).
     */
    public static function currentPeriod(BudgetVersion $version, Carbon $today): int
    {
        $start = Carbon::parse($version->year_start)->startOfMonth();

        return max(0, min(self::Count, (int) round($start->diffInMonths($today->copy()->startOfMonth(), false)) + 1));
    }

    /**
     * Label of a period for the screens and exports: the calendar month (Y-m).
     */
    public static function label(BudgetVersion $version, int $period): string
    {
        return self::periodStart($version, $period)->format('Y-m');
    }
}
