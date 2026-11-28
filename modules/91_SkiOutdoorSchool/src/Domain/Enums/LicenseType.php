<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Enums;

/**
 * Professional qualification of an instructor or mountain guide.
 */
enum LicenseType: string
{
    case SWISS_SNOWSPORTS = 'SWISS_SNOWSPORTS';
    case UIAGM_IFMGA = 'UIAGM_IFMGA';
    case AMSI_FISI = 'AMSI_FISI';
    case OTHER = 'OTHER';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
