<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Domain\Enums;

enum JourneyStepType: string
{
    case SendEmail = 'send_email';
    case SendWhatsapp = 'send_whatsapp';
    case SendSms = 'send_sms';
    case Wait = 'wait';
    case Condition = 'condition';
    case CrmTask = 'crm_task';
    case End = 'end';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
