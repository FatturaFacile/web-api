<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Commands;

use Illuminate\Support\Carbon;

/**
 * Plans the due recurring interventions of the active seasonal contracts of a week into routes of the crews.
 */
final readonly class OptimizeWeeklyCrewRoutesCommand
{
    /**
     * @param  Carbon  $weekStart  Monday of the week
     * @param  list<string>|null  $crewTeamIds  crews to use (all active crews when null)
     */
    public function __construct(
        public Carbon $weekStart,
        public ?array $crewTeamIds = null,
        public bool $refreshWeather = true,
    ) {}
}
