<?php

declare(strict_types=1);

namespace App\Enums\Privacy;

enum DsarStatus: string
{
    case Open = 'open';
    case IdentityVerified = 'identity_verified';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case PartiallyRefusedFiscalRetention = 'partially_refused_fiscal_retention';
    case Rejected = 'rejected';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
