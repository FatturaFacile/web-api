<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Domain\Enums;

enum SuppressionReason: string
{
    case HardBounce = 'hard_bounce';
    case SpamComplaint = 'spam_complaint';
    case Unsubscribe = 'unsubscribe';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
