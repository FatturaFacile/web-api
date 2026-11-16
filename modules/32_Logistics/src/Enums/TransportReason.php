<?php

declare(strict_types=1);

namespace Modules\Logistics\Enums;

enum TransportReason: string
{
    case Sale = 'sale';
    case InternalTransfer = 'internal_transfer';
    case Subcontracting = 'subcontracting';
    case Consignment = 'consignment';
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
            self::Repair => 'Riparazione',
            self::Gift => 'Omaggio',
            self::Return => 'Reso',
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
