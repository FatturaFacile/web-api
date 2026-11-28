<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Domain\Enums;

enum CguReferenceType: string
{
    case MRNInbound = 'MRN_Inbound';
    case MRNDischarge = 'MRN_Discharge';
    case ManualAdjustment = 'Manual_Adjustment';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
