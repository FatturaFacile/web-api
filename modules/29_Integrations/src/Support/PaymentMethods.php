<?php

declare(strict_types=1);

namespace Modules\Integrations\Support;

/**
 * Payment methods a payer can pick on the portal. Each gateway declares which of them it can open a page for.
 */
final class PaymentMethods
{
    public const string Card = 'card';

    public const string Twint = 'twint';

    public const string Sepa = 'sepa';

    public const string Paypal = 'paypal';

    public const string Qr = 'qr';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::Card, self::Twint, self::Sepa, self::Paypal, self::Qr];
    }

    /** Stripe `payment_method_types` and the currency each one settles in (Stripe: TWINT CHF, SEPA debit and PayPal EUR, card any). */
    public const array StripeTypes = [
        self::Card => ['type' => 'card', 'currencies' => ['EUR', 'CHF']],
        self::Twint => ['type' => 'twint', 'currencies' => ['CHF']],
        self::Sepa => ['type' => 'sepa_debit', 'currencies' => ['EUR']],
        self::Paypal => ['type' => 'paypal', 'currencies' => ['EUR']],
    ];
}
