<?php

declare(strict_types=1);

namespace Modules\Logistics\Enums;

/**
 * How the goods reach the buyer. Triangular and dropshipping shipments leave the supplier's (or a third party's)
 * warehouse straight for the final recipient while the invoice goes to the buyer.
 */
enum ShipmentFlow: string
{
    case Direct = 'direct';
    case Triangular = 'triangular';
    case Dropshipping = 'dropshipping';

    public function isThirdParty(): bool
    {
        return $this !== self::Direct;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
