<?php

declare(strict_types=1);

namespace Modules\HumanResources\Enums;

enum OfferStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Signed = 'signed';
    case Declined = 'declined';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Statuses that block the creation of another offer for the same application.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Draft, self::Sent, self::Signed];
    }
}
