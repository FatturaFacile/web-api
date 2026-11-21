<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Enums;

use Carbon\CarbonImmutable;

enum CertificateStatus: string
{
    case Valid = 'valid';
    case ExpiringSoon = 'expiring_soon';
    case Expired = 'expired';
    case Revoked = 'revoked';

    /** Days before the expiry where the certificate turns "expiring soon". */
    public const int ExpiringWindowDays = 60;

    /**
     * The state of a certificate at a given day. A revoked certificate stays revoked.
     */
    public static function at(?CarbonImmutable $expiresAt, CarbonImmutable $day, bool $revoked = false): self
    {
        if ($revoked) {
            return self::Revoked;
        }

        if ($expiresAt === null) {
            return self::Valid;
        }

        $daysLeft = (int) $day->startOfDay()->diffInDays($expiresAt->startOfDay(), false);

        return match (true) {
            $daysLeft < 0 => self::Expired,
            $daysLeft <= self::ExpiringWindowDays => self::ExpiringSoon,
            default => self::Valid,
        };
    }
}
