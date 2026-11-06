<?php

declare(strict_types=1);

namespace Modules\SDI\Enums;

use App\Models\Tenant;

/**
 * RegimeFiscaleType of the FatturaPA schema v1.2.3 (the schema has no RF03).
 */
enum RegimeFiscale: string
{
    case Rf01 = 'RF01';
    case Rf02 = 'RF02';
    case Rf04 = 'RF04';
    case Rf05 = 'RF05';
    case Rf06 = 'RF06';
    case Rf07 = 'RF07';
    case Rf08 = 'RF08';
    case Rf09 = 'RF09';
    case Rf10 = 'RF10';
    case Rf11 = 'RF11';
    case Rf12 = 'RF12';
    case Rf13 = 'RF13';
    case Rf14 = 'RF14';
    case Rf15 = 'RF15';
    case Rf16 = 'RF16';
    case Rf17 = 'RF17';
    case Rf18 = 'RF18';
    case Rf19 = 'RF19';
    case Rf20 = 'RF20';

    /**
     * A tenant that states no regime is in the ordinary one.
     */
    public static function ofTenant(?Tenant $tenant): self
    {
        return self::tryFrom((string) ($tenant?->settings['regime_fiscale'] ?? '')) ?? self::Rf01;
    }

    public function isForfettario(): bool
    {
        return $this === self::Rf19;
    }
}
