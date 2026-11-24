<?php

declare(strict_types=1);

namespace Modules\Planning\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Modules\Planning\Models\PlanningScenario;

/**
 * The planning grid is monthly: every date is moved to the first day of its month.
 */
final class PlanningPeriods
{
    public static function monthStart(CarbonInterface|string $date): Carbon
    {
        return Carbon::parse($date)->startOfMonth()->startOfDay();
    }

    /**
     * The months (first days, Y-m-d) the horizon of a scenario covers.
     *
     * @return list<string>
     */
    public static function months(PlanningScenario $scenario): array
    {
        $months = [];
        $cursor = self::monthStart($scenario->horizon_start);
        $last = self::monthStart($scenario->horizon_end);

        while ($cursor->lessThanOrEqualTo($last)) {
            $months[] = $cursor->toDateString();
            $cursor = $cursor->copy()->addMonthNoOverflow();
        }

        return $months;
    }

    public static function covers(PlanningScenario $scenario, CarbonInterface|string $date): bool
    {
        $month = self::monthStart($date);

        return $month->greaterThanOrEqualTo(self::monthStart($scenario->horizon_start))
            && $month->lessThanOrEqualTo(self::monthStart($scenario->horizon_end));
    }
}
