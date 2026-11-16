<?php

declare(strict_types=1);

namespace Modules\Logistics\Enums;

enum DeliveryNoteType: string
{
    case OutboundSale = 'outbound_sale';
    case InternalTransfer = 'internal_transfer';
    case SubcontractingOut = 'subcontracting_out';
    case SubcontractingReturn = 'subcontracting_return';
    case CustomerConsignment = 'customer_consignment';
    case CustomerConsignmentReturn = 'customer_consignment_return';
    case VendorConsignmentReturn = 'vendor_consignment_return';
    case ReturnFromCustomer = 'return_from_customer';

    /** Numbering section ("sezionale"): each section has its own progressive sequence. */
    public function numberingSection(): string
    {
        return match ($this) {
            self::OutboundSale, self::ReturnFromCustomer => 'delivery_note',
            self::InternalTransfer => 'ddt_transfer',
            self::SubcontractingOut, self::SubcontractingReturn => 'ddt_subcontract',
            self::CustomerConsignment, self::CustomerConsignmentReturn, self::VendorConsignmentReturn => 'ddt_consignment',
        };
    }

    public function defaultTransportReason(): TransportReason
    {
        return match ($this) {
            self::OutboundSale => TransportReason::Sale,
            self::InternalTransfer => TransportReason::InternalTransfer,
            self::SubcontractingOut => TransportReason::Subcontracting,
            self::SubcontractingReturn => TransportReason::SubcontractorReturn,
            self::CustomerConsignment => TransportReason::ConsignmentDelivery,
            self::CustomerConsignmentReturn, self::VendorConsignmentReturn => TransportReason::ConsignmentReturn,
            self::ReturnFromCustomer => TransportReason::Return,
        };
    }

    /** Types that have no commercial counterparty (customer) on the document. */
    public function requiresCustomer(): bool
    {
        return in_array($this, [self::OutboundSale, self::ReturnFromCustomer], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
