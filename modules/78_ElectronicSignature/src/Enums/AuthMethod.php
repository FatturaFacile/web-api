<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Enums;

/**
 * How a recipient proves their identity on top of the personal link. SPID / CIE and SwissID are run by the provider.
 */
enum AuthMethod: string
{
    case EmailLink = 'email_link';
    case SmsOtp = 'sms_otp';
    case MobileApp = 'mobile_app';
    case SpidCie = 'spid_cie';
    case SwissId = 'swiss_id';

    /**
     * Whether the platform itself sends and checks a one-time code.
     */
    public function isPlatformOtp(): bool
    {
        return $this === self::SmsOtp;
    }

    /**
     * Whether the identity check happens at the provider (push to an app, national identity scheme).
     */
    public function isProviderManaged(): bool
    {
        return in_array($this, [self::MobileApp, self::SpidCie, self::SwissId], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $method): string => $method->value, self::cases());
    }
}
