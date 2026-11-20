<?php

declare(strict_types=1);

namespace Modules\Forecasting\Domain\Enums;

/**
 * Fiscal jurisdiction that decides the statutory cash outflow calendar.
 */
enum JurisdictionCode: string
{
    case Ch = 'CH';
    case It = 'IT';

    /**
     * Switzerland for a CH tenant, Italy for any other country (the ERP's default jurisdiction).
     */
    public static function fromCountry(?string $country): self
    {
        return strtoupper((string) $country) === 'CH' ? self::Ch : self::It;
    }
}
