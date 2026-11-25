<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Domain\Enums;

enum VanWarehouseStatus: string
{
    case Active = 'active';
    case OnRoute = 'on_route';
    case Maintenance = 'maintenance';
    case Inactive = 'inactive';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
