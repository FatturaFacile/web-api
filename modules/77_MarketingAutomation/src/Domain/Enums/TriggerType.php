<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Domain\Enums;

enum TriggerType: string
{
    case ScheduledBroadcast = 'scheduled_broadcast';
    case AbandonedCart = 'abandoned_cart';
    case CustomerDormant60Days = 'customer_dormant_60_days';
    case WarrantyExpiring = 'warranty_expiring';
    case PostFairLeadFollowup = 'post_fair_lead_followup';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
