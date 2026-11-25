<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\Enums;

use Modules\PassengerTransport\Domain\Enums\Concerns\EnumValues;

enum VehicleCategory: string
{
    use EnumValues;

    case SedanNcc = 'sedan_ncc';
    case LuxuryVanNcc = 'luxury_van_ncc';
    case Minibus916 = 'minibus_9_16';
    case GtCoachOver35t = 'gt_coach_over_3_5t';
    case SchoolBus = 'school_bus';
    case Taxi = 'taxi';

    /**
     * Cars and vans operated under an NCC authorization (noleggio con conducente) subject to the electronic service sheet.
     */
    public function isNcc(): bool
    {
        return in_array($this, [self::SedanNcc, self::LuxuryVanNcc, self::Minibus916], true);
    }

    /**
     * Coaches and buses whose driver needs a professional passenger licence (D/DE, CQC Persone, CZV).
     */
    public function isBusLike(): bool
    {
        return in_array($this, [self::Minibus916, self::GtCoachOver35t, self::SchoolBus], true);
    }
}
