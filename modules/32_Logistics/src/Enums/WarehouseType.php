<?php

declare(strict_types=1);

namespace Modules\Logistics\Enums;

enum WarehouseType: string
{
    case Standard = 'standard';
    case Transit = 'transit';
    case MobileVan = 'mobile_van';
    case CustomerConsignment = 'customer_consignment';
    case VendorConsignment = 'vendor_consignment';
    case Subcontractor = 'subcontractor';

    /** Warehouses that hold stock outside our premises and belong to the consignment/subcontract registers. */
    public function isThirdPartyLocation(): bool
    {
        return in_array($this, [self::CustomerConsignment, self::VendorConsignment, self::Subcontractor], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
