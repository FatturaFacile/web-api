<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Domain\Enums;

enum LoadManifestStatus: string
{
    case Planned = 'planned';
    case DdtIssued = 'ddt_issued';
    case InTransit = 'in_transit';
    case Settled = 'settled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
