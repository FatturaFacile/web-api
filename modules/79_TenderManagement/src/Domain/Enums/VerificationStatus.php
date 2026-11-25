<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Domain\Enums;

enum VerificationStatus: string
{
    case Valid = 'Valid';
    case ExpiringSoon = 'ExpiringSoon';
    case Expired = 'Expired';
    case PendingRenewal = 'PendingRenewal';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
