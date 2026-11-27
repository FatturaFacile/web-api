<?php

declare(strict_types=1);

namespace Modules\GreenCare\Events;

/**
 * Stops of planned routes were moved to a clear day because of a rain or frost alert: dispatchers and crews must be told.
 */
final readonly class RoutesRescheduledDueToWeather
{
    /**
     * @param  list<string>  $affectedRouteIds  routes that lost stops or hold an alert
     * @param  list<string>  $movedStopIds
     */
    public function __construct(
        public string $tenantId,
        public array $affectedRouteIds,
        public array $movedStopIds,
    ) {}
}
