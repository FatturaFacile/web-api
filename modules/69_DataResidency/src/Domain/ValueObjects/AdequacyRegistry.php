<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\ValueObjects;

use Modules\DataResidency\Domain\Enums\JurisdictionZone;
use Modules\DataResidency\Domain\Enums\TransferDecision;

/**
 * The statutory adequacy baseline: the Swiss Federal Council list (Annex 1 DSV, Art. 16 nLPD) and the EU Commission
 * adequacy decisions (Art. 45 GDPR). It seeds core_cross_border_transfer_rules and is the fallback when the table has no
 * row for a destination. Any country not listed is a non-adequate third country.
 */
final class AdequacyRegistry
{
    /** Countries with a Swiss adequacy finding (besides the EEA, which is adequate by Annex 1). */
    private const array CH_ADEQUATE_EXTRA = ['GB', 'AD', 'AR', 'CA', 'FO', 'GG', 'GI', 'IM', 'IL', 'JE', 'NZ', 'UY', 'JP', 'KR'];

    /** Countries with an EU adequacy decision (Switzerland included). */
    private const array EU_ADEQUATE_EXTRA = ['CH', 'GB', 'AD', 'AR', 'CA', 'FO', 'GG', 'IM', 'IL', 'JP', 'JE', 'NZ', 'KR', 'UY'];

    public static function hasChAdequacy(string $iso2): bool
    {
        $iso2 = strtoupper($iso2);

        return in_array($iso2, JurisdictionZone::EEA_PLUS_CH, true) || in_array($iso2, self::CH_ADEQUATE_EXTRA, true);
    }

    public static function hasEuAdequacy(string $iso2): bool
    {
        $iso2 = strtoupper($iso2);

        return in_array($iso2, JurisdictionZone::EEA_PLUS_CH, true) || in_array($iso2, self::EU_ADEQUATE_EXTRA, true);
    }

    /**
     * The baseline decision for a destination as seen from a jurisdiction zone. A Swiss-only tenant needs the Swiss
     * finding, an Italian or EEA tenant the EU one; a destination adequate for neither is blocked.
     */
    public static function decisionFor(JurisdictionZone $zone, string $iso2): TransferDecision
    {
        $adequate = $zone->isSwiss() ? self::hasChAdequacy($iso2) : self::hasEuAdequacy($iso2);

        return $adequate ? TransferDecision::Allowed : TransferDecision::Blocked;
    }

    public static function legalBasis(JurisdictionZone $zone, string $iso2): string
    {
        return $zone->isSwiss() ? 'Art. 16 Abs. 1 nLPD / Annex 1 DSV' : 'GDPR Art. 45';
    }

    /**
     * Every country the baseline names, with its display name.
     *
     * @return array<string, string>
     */
    public static function countries(): array
    {
        return [
            'CH' => 'Switzerland', 'IT' => 'Italy', 'DE' => 'Germany', 'FR' => 'France', 'AT' => 'Austria', 'ES' => 'Spain',
            'NL' => 'Netherlands', 'BE' => 'Belgium', 'IE' => 'Ireland', 'LU' => 'Luxembourg', 'PT' => 'Portugal',
            'SE' => 'Sweden', 'DK' => 'Denmark', 'FI' => 'Finland', 'PL' => 'Poland', 'GR' => 'Greece', 'LI' => 'Liechtenstein',
            'NO' => 'Norway', 'IS' => 'Iceland', 'GB' => 'United Kingdom', 'CA' => 'Canada', 'JP' => 'Japan',
            'IL' => 'Israel', 'NZ' => 'New Zealand', 'KR' => 'South Korea', 'AR' => 'Argentina', 'UY' => 'Uruguay',
            'US' => 'United States', 'CN' => 'China', 'IN' => 'India', 'RU' => 'Russia', 'BR' => 'Brazil', 'SG' => 'Singapore',
            'AE' => 'United Arab Emirates', 'AU' => 'Australia', 'TR' => 'Turkey', 'ZA' => 'South Africa',
        ];
    }
}
