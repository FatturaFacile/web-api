<?php

declare(strict_types=1);

namespace Modules\Plm\Enums;

enum CadSourceSystem: string
{
    case SolidWorks = 'SolidWorks';
    case Inventor = 'Inventor';
    case Creo = 'Creo';
    case NX = 'NX';
    case Eplan = 'Eplan';
    case Altium = 'Altium';
    case GenericXmlCsvStep = 'GenericXmlCsvStep';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
