<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Domain\Enums;

enum MessageStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case SoftBounced = 'soft_bounced';
    case HardBounced = 'hard_bounced';
    case Complained = 'complained';
    case Failed = 'failed';
    case Suppressed = 'suppressed';
    case Blocked = 'blocked';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
