<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Domain\Enums;

enum CommissionAccrualBasis: string
{
    case Invoiced = 'invoiced';
    case Collected = 'collected';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
