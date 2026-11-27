<?php

declare(strict_types=1);

namespace Modules\GreenCare\Domain\Enums;

/**
 * Frequency of the fixed installments of a contract.
 */
enum InstallmentFrequency: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SeasonalSplit = 'seasonal_split';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
