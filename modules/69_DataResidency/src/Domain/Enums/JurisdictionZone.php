<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\Enums;

/**
 * The legal perimeter in which the data of a tenant (or of one legal entity) must physically stay.
 */
enum JurisdictionZone: string
{
    case StrictChOnly = 'Strict-CH-Only';
    case StrictItOnly = 'Strict-IT-Only';
    case EeaPlusAdequacyCh = 'EEA-Plus-Adequacy-CH';

    /** Countries of the EEA plus Switzerland: the corridor of EEA-Plus-Adequacy-CH. */
    public const array EEA_PLUS_CH = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
        'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO', 'CH',
    ];

    /**
     * Countries whose infrastructure may host the data of this zone.
     *
     * @return list<string>
     */
    public function allowedCountries(): array
    {
        return match ($this) {
            self::StrictChOnly => ['CH'],
            self::StrictItOnly => ['IT'],
            self::EeaPlusAdequacyCh => self::EEA_PLUS_CH,
        };
    }

    public function allowsCountry(string $iso2): bool
    {
        return in_array(strtoupper($iso2), $this->allowedCountries(), true);
    }

    /** The regulation family quoted on the certificate. */
    public function isSwiss(): bool
    {
        return $this === self::StrictChOnly;
    }

    /**
     * @return list<string>
     */
    public function complianceFrameworks(): array
    {
        return match ($this) {
            self::StrictChOnly => ['nLPD Art. 16-17', 'FINMA Circ. 2018/3', 'Art. 321 CP', 'Art. 47 LBCR', 'ISO/IEC 27001'],
            self::StrictItOnly => ['GDPR Art. 44-50', 'ACN/AgID', 'NIS2', 'ISO/IEC 27001'],
            self::EeaPlusAdequacyCh => ['nLPD Art. 16-17', 'GDPR Art. 44-50', 'ACN/AgID', 'ISO/IEC 27001'],
        };
    }
}
