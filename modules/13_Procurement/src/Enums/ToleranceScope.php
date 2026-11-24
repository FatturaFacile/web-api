<?php

declare(strict_types=1);

namespace Modules\Procurement\Enums;

/**
 * Where a tolerance rule applies. A narrower scope wins over a wider one: rule, then supplier, then item category, then
 * the whole tenant.
 */
enum ToleranceScope: string
{
    case Tenant = 'tenant';
    case ItemCategory = 'item_category';
    case Supplier = 'supplier';
    case Rule = 'rule';

    public function specificity(): int
    {
        return match ($this) {
            self::Tenant => 1,
            self::ItemCategory => 2,
            self::Supplier => 3,
            self::Rule => 4,
        };
    }
}
