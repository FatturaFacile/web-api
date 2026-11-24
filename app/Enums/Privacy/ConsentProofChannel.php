<?php

declare(strict_types=1);

namespace App\Enums\Privacy;

enum ConsentProofChannel: string
{
    case WebForm = 'web_form';
    case DoubleOptInEmail = 'double_opt_in_email';
    case SignedPaper = 'signed_paper';
    case CustomerPortal = 'customer_portal';
    case ApiWebhook = 'api_webhook';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
