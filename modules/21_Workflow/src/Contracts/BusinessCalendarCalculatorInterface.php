<?php

declare(strict_types=1);

namespace Modules\Workflow\Contracts;

use Carbon\Carbon;
use Modules\Workflow\Models\BusinessCalendar;

interface BusinessCalendarCalculatorInterface
{
    /**
     * Calculates precise deadline timestamp by traversing business hours and skipping weekends/holidays.
     */
    public function calculateDeadline(Carbon $startTime, int $businessHours, ?BusinessCalendar $calendar = null): Carbon;

    /**
     * Determines whether a given datetime falls within operational business hours.
     */
    public function isBusinessTime(Carbon $datetime, ?BusinessCalendar $calendar = null): bool;
}
