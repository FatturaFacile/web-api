<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Domain\Enums;

enum MailProvider: string
{
    case Smtp = 'smtp';
    case AmazonSes = 'ses';
    case SendGrid = 'sendgrid';
    case Mailgun = 'mailgun';
    case Infomaniak = 'infomaniak';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
