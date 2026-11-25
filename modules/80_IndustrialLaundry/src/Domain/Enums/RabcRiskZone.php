<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Domain\Enums;

/**
 * Risk zone of the linen a batch washes, per the UNI EN 14065 RABC (Risk Analysis and Biocontamination Control) analysis:
 * the standard textile of hotels, the infectious-risk linen of clinics and the linen that goes back to a sterile or protected ward.
 */
enum RabcRiskZone: string
{
    case Standard = 'standard';
    case Contaminated = 'contaminated';
    case Protected = 'protected';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function requiresDisinfection(): bool
    {
        return $this !== self::Standard;
    }
}
