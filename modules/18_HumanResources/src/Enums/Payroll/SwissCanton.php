<?php

declare(strict_types=1);

namespace Modules\HumanResources\Enums\Payroll;

/**
 * The 26 Swiss cantons (ISO 3166-2:CH subdivision codes without the country prefix).
 */
enum SwissCanton: string
{
    case AG = 'AG';
    case AI = 'AI';
    case AR = 'AR';
    case BE = 'BE';
    case BL = 'BL';
    case BS = 'BS';
    case FR = 'FR';
    case GE = 'GE';
    case GL = 'GL';
    case GR = 'GR';
    case JU = 'JU';
    case LU = 'LU';
    case NE = 'NE';
    case NW = 'NW';
    case OW = 'OW';
    case SG = 'SG';
    case SH = 'SH';
    case SO = 'SO';
    case SZ = 'SZ';
    case TG = 'TG';
    case TI = 'TI';
    case UR = 'UR';
    case VD = 'VD';
    case VS = 'VS';
    case ZG = 'ZG';
    case ZH = 'ZH';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
