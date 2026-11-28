<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Enums;

/**
 * Mod 298 — CheeseType. Labels are the frontend keys DAIRY.ENUM.<ENUM>.<VALUE> (see the lookups).
 */
enum CheeseType: string
{
    use LabelledEnum;

    case ParmigianoReggianoDop = 'parmigiano_reggiano_dop';
    case GranaPadanoDop = 'grana_padano_dop';
    case GruyereAop = 'gruyere_aop';
    case SbrinzAop = 'sbrinz_aop';
    case AlpeTicineseDop = 'alpe_ticinese_dop';
    case Generic = 'generic';
}
