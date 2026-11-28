<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Domain\Enums;

enum DiscrepancyStatus: string
{
    case Matched = 'Matched';
    case MinorVariance = 'Minor_Variance';
    case CriticalAlertADMUDSC = 'Critical_Alert_ADM_UDSC';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
