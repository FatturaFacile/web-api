<?php

declare(strict_types=1);

namespace Modules\GreenCare\Domain\Enums;

/**
 * Equipment kinds carried by a crew.
 */
enum EquipmentType: string
{
    case Lawnmowers = 'lawnmowers';
    case RideOnMowers = 'ride_on_mowers';
    case Chainsaws = 'chainsaws';
    case HedgeTrimmers = 'hedge_trimmers';
    case Sprayers = 'sprayers';
    case SnowPlows = 'snow_plows';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
