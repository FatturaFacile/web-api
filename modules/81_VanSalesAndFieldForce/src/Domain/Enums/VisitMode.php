<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Domain\Enums;

enum VisitMode: string
{
    case VanSelling = 'van_selling';
    case FieldPresale = 'field_presale';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
