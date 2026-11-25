<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Domain\Enums;

enum Jurisdiction: string
{
    case SwissSimap = 'CH_SIMAP_LAPub';
    case ItalianAnac = 'IT_Dlgs36_2023_ANAC';
    case EuTed = 'EU_TED';
    case PrivateRfp = 'PrivateRfp';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
