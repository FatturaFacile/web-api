<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Domain\Enums;

enum AwardCriterion: string
{
    case Oepv = 'OEPV_MostEconomicallyAdvantageous';
    case LowestPrice = 'LowestPrice';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
