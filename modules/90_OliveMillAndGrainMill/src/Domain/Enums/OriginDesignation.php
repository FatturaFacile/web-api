<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\Enums;

enum OriginDesignation: string
{
    case Italia = 'ITALIA';
    case Ue = 'UE';
    case ExtraUe = 'EXTRA_UE';
    case DopSpecifica = 'DOP_SPECIFICA';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
