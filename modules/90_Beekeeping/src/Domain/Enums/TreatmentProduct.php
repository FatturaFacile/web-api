<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Domain\Enums;

enum TreatmentProduct: string
{
    case OxalicAcidSublimated = 'oxalic_acid_sublimated';
    case OxalicAcidTrickled = 'oxalic_acid_trickled';
    case Thymol = 'thymol';
    case FormicAcid = 'formic_acid';
    case Amitraz = 'amitraz';
    case Flumethrin = 'flumethrin';
    case TauFluvalinate = 'tau_fluvalinate';
    case BroodInterruption = 'brood_interruption';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
