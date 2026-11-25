<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Domain\Enums;

enum PortalPaymentProvider: string
{
    case Stripe = 'stripe';
    case Twint = 'twint';
    case SepaDd = 'sepa_dd';
    case QrBill = 'qr_bill';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
