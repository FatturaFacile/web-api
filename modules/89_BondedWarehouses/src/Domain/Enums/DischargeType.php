<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Domain\Enums;

enum DischargeType: string
{
    case IM4FreeCirculation = 'IM4_FreeCirculation';
    case EX3ReExport = 'EX3_ReExport';
    case PassarExport = 'Passar_Export';
    case T1Transit = 'T1_Transit';
    case UsualHandlingAnnex7103 = 'UsualHandling_Annex71_03';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
