<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Commands;

use Illuminate\Support\Carbon;

/**
 * Shifts the outdoor stops (mowing, spraying) of the planned routes of a period that a rain or frost alert hits to the next clear day of the same crew.
 */
final readonly class RescheduleRoutesOnWeatherAlertCommand
{
    public function __construct(
        public Carbon $from,
        public Carbon $to,
    ) {}
}
