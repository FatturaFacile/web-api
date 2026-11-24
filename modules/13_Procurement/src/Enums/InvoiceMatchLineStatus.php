<?php

declare(strict_types=1);

namespace Modules\Procurement\Enums;

/**
 * Outcome of one invoice line. PriceVariance, QuantityVariance, NotReceived and Unmatched block the invoice until the
 * line is accepted (Accepted) by a resolution.
 */
enum InvoiceMatchLineStatus: string
{
    case Matched = 'matched';
    case WithinTolerance = 'within_tolerance';
    case PriceVariance = 'price_variance';
    case QuantityVariance = 'quantity_variance';
    case NotReceived = 'not_received';
    case Unmatched = 'unmatched';
    case Accepted = 'accepted';

    public function isBlocking(): bool
    {
        return match ($this) {
            self::PriceVariance, self::QuantityVariance, self::NotReceived, self::Unmatched => true,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function blockingValues(): array
    {
        return [self::PriceVariance->value, self::QuantityVariance->value, self::NotReceived->value, self::Unmatched->value];
    }
}
