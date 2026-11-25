<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Domain\Enums;

use Modules\CustomerSatisfaction\Domain\Enums\Concerns\EnumValues;

enum DispatchStatus: string
{
    use EnumValues;

    case Scheduled = 'scheduled';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Responded = 'responded';
    case Throttled = 'throttled';
    case Suppressed = 'suppressed';
    case Failed = 'failed';
    case Expired = 'expired';

    /**
     * Dispatches that reached (or are about to reach) the customer: they count for the survey fatigue cooldown.
     *
     * @return list<string>
     */
    public static function counting(): array
    {
        return [self::Scheduled->value, self::Sent->value, self::Delivered->value, self::Responded->value];
    }
}
