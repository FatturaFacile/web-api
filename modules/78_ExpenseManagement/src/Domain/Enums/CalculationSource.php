<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Domain\Enums;

enum CalculationSource: string
{
    case ChTcs = 'CH_TCS';
    case ChSpesenreglement = 'CH_SPESENREGLEMENT';
    case ItAciTable = 'IT_ACI_TABLE';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
