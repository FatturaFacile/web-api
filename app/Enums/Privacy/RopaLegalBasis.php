<?php

declare(strict_types=1);

namespace App\Enums\Privacy;

enum RopaLegalBasis: string
{
    case Consent = 'consent';
    case Contract = 'contract';
    case LegalObligation = 'legal_obligation';
    case VitalInterests = 'vital_interests';
    case PublicInterest = 'public_interest';
    case LegitimateInterest = 'legitimate_interest';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
