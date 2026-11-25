<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Domain\Enums;

enum CustomerHierarchyRole: string
{
    case Holding = 'holding';
    case Subsidiary = 'subsidiary';
    case BranchSite = 'branch_site';
    case B2cConsumer = 'b2c_consumer';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
