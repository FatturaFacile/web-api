<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\Enums;

enum ByproductType: string
{
    case SansaVergine = 'SANSA_VERGINE';
    case Nocciolino = 'NOCCIOLINO';
    case AcqueVegetazione = 'ACQUE_VEGETAZIONE';
    case Crusca = 'CRUSCA';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
