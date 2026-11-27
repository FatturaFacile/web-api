<?php

declare(strict_types=1);

namespace Modules\GreenCare\Domain\Enums;

/**
 * Weather situation of a crew route schedule.
 */
enum WeatherAlertStatus: string
{
    case Clear = 'clear';
    case RainWarning = 'rain_warning';
    case FrostAlert = 'frost_alert';
    case RescheduledDueToWeather = 'rescheduled_due_to_weather';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
