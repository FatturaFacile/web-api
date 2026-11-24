<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Domain\Enums;

enum CampaignChannel: string
{
    case EmailDem = 'email_dem';
    case WhatsAppMarketing = 'whatsapp_marketing';
    case SmsPromo = 'sms_promo';
    case MultiChannelJourney = 'multi_channel_journey';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
