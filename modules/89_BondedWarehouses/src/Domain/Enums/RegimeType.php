<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Domain\Enums;

enum RegimeType: string
{
    case CDUPrivate7100 = 'CDU_Private_7100';
    case SwissOZL = 'Swiss_OZL';
    case SwissZollfreilagerZE = 'Swiss_Zollfreilager_ZE';
    case VatDepotArt50Bis = 'VatDepot_Art50Bis';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
