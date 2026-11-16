<?php

declare(strict_types=1);

namespace Modules\Logistics\Enums;

use Modules\Inventory\Models\Warehouse;

/**
 * Legal ownership of the stock booked on a ledger entry, independent from where it physically sits.
 */
enum OwnershipType: string
{
    case Owned = 'owned';
    case VendorConsignment = 'vendor_consignment';
    case CustomerConsignment = 'customer_consignment';
    case SubcontractorHeld = 'subcontractor_held';

    /** Ownership implied by the warehouse a movement is booked on. */
    public static function forWarehouse(Warehouse $warehouse): self
    {
        if ($warehouse->warehouse_type === WarehouseType::VendorConsignment->value || $warehouse->legal_owner_type === LegalOwnerType::Vendor->value) {
            return self::VendorConsignment;
        }

        return match ($warehouse->warehouse_type) {
            WarehouseType::CustomerConsignment->value => self::CustomerConsignment,
            WarehouseType::Subcontractor->value => self::SubcontractorHeld,
            default => self::Owned,
        };
    }

    /** Only goods legally owned by the company are balance-sheet assets. */
    public function isCompanyOwned(): bool
    {
        return $this !== self::VendorConsignment;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
