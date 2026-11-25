<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Domain\Enums;

enum RfmSegmentCode: string
{
    case Champions = 'champions';
    case Loyal = 'loyal';
    case AtRisk = 'at_risk';
    case Hibernating = 'hibernating';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
