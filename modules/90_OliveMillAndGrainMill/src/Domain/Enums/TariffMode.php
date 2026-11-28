<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\Enums;

enum TariffMode: string
{
    case PerQuintalOlives = 'PER_QUINTAL_OLIVES';
    case PerKgOil = 'PER_KG_OIL';
    case FlatFee = 'FLAT_FEE';
    case PurePercentageMolenda = 'PURE_PERCENTAGE_MOLENDA';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
