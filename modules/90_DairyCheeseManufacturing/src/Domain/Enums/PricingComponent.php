<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Enums;

/**
 * Mod 298 — PricingComponent. Labels are the frontend keys DAIRY.ENUM.<ENUM>.<VALUE> (see the lookups).
 */
enum PricingComponent: string
{
    use LabelledEnum;

    case Fat = 'fat';
    case Protein = 'protein';
    case Tbc = 'tbc';
    case Scc = 'scc';
    case Inhibitor = 'inhibitor';
    case FreezingPoint = 'freezing_point';
    case Casein = 'casein';
    case Urea = 'urea';
    case CheeseMilkSupplement = 'cheese_milk_supplement';
    case DopPremium = 'dop_premium';
}
