<?php

declare(strict_types=1);

namespace Modules\GreenCare\Domain\Enums;

/**
 * Services a seasonal contract can include.
 */
enum ServiceType: string
{
    case Mowing = 'mowing';
    case Hedging = 'hedging';
    case Fertilization = 'fertilization';
    case IrrigationStartup = 'irrigation_startup';
    case IrrigationWinterization = 'irrigation_winterization';
    case SnowClearing = 'snow_clearing';
    case Pruning = 'pruning';
    case Phytosanitary = 'phytosanitary';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
