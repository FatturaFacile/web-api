<?php

declare(strict_types=1);

namespace Modules\Logistics\Enums;

enum TransferPurpose: string
{
    case InterWarehouse = 'inter_warehouse';
    case TechnicianVan = 'technician_van';
    case CustomerConsignment = 'customer_consignment';
    case Subcontracting = 'subcontracting';

    public function deliveryNoteType(): DeliveryNoteType
    {
        return match ($this) {
            self::InterWarehouse, self::TechnicianVan => DeliveryNoteType::InternalTransfer,
            self::CustomerConsignment => DeliveryNoteType::CustomerConsignment,
            self::Subcontracting => DeliveryNoteType::SubcontractingOut,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
