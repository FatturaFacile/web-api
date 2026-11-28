<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Enums;

/**
 * Contractual regime of the instructor with the school, printed as a note on the payout statement.
 */
enum CollaborationRegime: string
{
    case FREELANCE = 'FREELANCE';
    case OCCASIONAL = 'OCCASIONAL';
    case SPORT_COLLABORATION = 'SPORT_COLLABORATION';
    case EMPLOYEE = 'EMPLOYEE';
    case SWISS_INDEPENDENT = 'SWISS_INDEPENDENT';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
