<?php

declare(strict_types=1);

namespace Modules\Logistics\Enums;

enum TransportReason: string
{
    case Sale = 'sale';
    case InternalTransfer = 'internal_transfer';
    case Subcontracting = 'subcontracting';
    case Consignment = 'consignment';
    case ConsignmentDelivery = 'consignment_delivery';
    case ConsignmentReturn = 'consignment_return';
    case SubcontractorReturn = 'subcontractor_return';
    case SubcontractedGoodsReturn = 'subcontracted_goods_return';
    case OnApproval = 'on_approval';
    case Repair = 'repair';
    case Gift = 'gift';
    case Return = 'return';

    /** Legacy free-text label stored in `delivery_notes.transport_reason` (kept for PDF / old clients). */
    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Vendita',
            self::InternalTransfer => 'Trasferimento interno',
            self::Subcontracting => 'Conto lavorazione',
            self::Consignment => 'Conto deposito',
            self::ConsignmentDelivery => 'Conto deposito / conto vendita',
            self::ConsignmentReturn => 'Reso da conto deposito',
            self::SubcontractorReturn => 'Reso da terzista',
            self::SubcontractedGoodsReturn => 'Reso lavorato',
            self::OnApproval => 'Conto visione',
            self::Repair => 'Riparazione',
            self::Gift => 'Omaggio',
            self::Return => 'Reso',
        };
    }

    /** Goods sent on approval (conto visione) are not sold yet: no invoice until the customer accepts them. */
    public function isBillable(): bool
    {
        return $this !== self::OnApproval;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
