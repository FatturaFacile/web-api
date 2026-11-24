<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Domain\Enums;

enum ConsentEventType: string
{
    case OptIn = 'opt_in';
    case DoubleOptInConfirmed = 'double_opt_in_confirmed';
    case ProfilingOptIn = 'profiling_opt_in';
    case ProfilingOptOut = 'profiling_opt_out';
    case OptOut = 'opt_out';
    case Unsubscribe = 'unsubscribe';
    case SoftOptInEligible = 'soft_opt_in_eligible';
    case HardBounce = 'hard_bounce';
    case SpamComplaint = 'spam_complaint';
    case Resubscribe = 'resubscribe';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
