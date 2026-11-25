<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Domain\Enums;

enum VanFiscalDocumentType: string
{
    case ImmediateInvoice = 'immediate_invoice';
    case DeliveryNoteDdt = 'delivery_note_ddt';
    case CommercialReceiptRt = 'commercial_receipt_rt';
    case SalesOrder = 'sales_order';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
