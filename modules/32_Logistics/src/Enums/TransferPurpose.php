<?php

declare(strict_types=1);

namespace Modules\Logistics\Enums;

enum TransferPurpose: string
{
    case InterWarehouse = 'inter_warehouse';
    case TechnicianVan = 'technician_van';
    case InterCompany = 'inter_company';
    case CustomerConsignment = 'customer_consignment';
    case ConsignmentReturn = 'consignment_return';
    case Subcontracting = 'subcontracting';
    case SubcontractingReturn = 'subcontracting_return';

    public function deliveryNoteType(): DeliveryNoteType
    {
        return match ($this) {
            self::InterWarehouse, self::TechnicianVan, self::InterCompany => DeliveryNoteType::InternalTransfer,
            self::CustomerConsignment => DeliveryNoteType::CustomerConsignment,
            self::ConsignmentReturn => DeliveryNoteType::CustomerConsignmentReturn,
            self::Subcontracting => DeliveryNoteType::SubcontractingOut,
            self::SubcontractingReturn => DeliveryNoteType::SubcontractingReturn,
        };
    }

    /** Whether the DDT carries prices (fiscal value of the goods) — internal moves between own warehouses do not. */
    public function showsPricesOnDdt(): bool
    {
        return ! in_array($this, [self::InterWarehouse, self::TechnicianVan], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
