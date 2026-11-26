<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Enums;

/**
 * Who published a rate: the European Central Bank, the Swiss National Bank, the Swiss Federal Tax Administration (ESTV
 * monthly average), or a person of the company (manual and contract rates).
 */
enum RateSource: string
{
    case Ecb = 'ecb';
    case Snb = 'snb';
    case Estv = 'estv';
    case Manual = 'manual';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $source): string => $source->value, self::cases());
    }
}
