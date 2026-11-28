<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\Enums;

enum ProcessingMode: string
{
    case ContoTerzi = 'CONTO_TERZI';
    case ContoProprio = 'CONTO_PROPRIO';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
