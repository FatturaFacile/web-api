<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\Enums;

use Modules\CorporateConventions\Domain\Enums\Concerns\EnumValues;

enum SubsidyPurpose: string
{
    use EnumValues;

    case Dlgs8108Vdt = 'DLGS_81_08_VDT';
    case PreventiveHealthcare = 'PREVENTIVE_HEALTHCARE';
    case SportsWellness = 'SPORTS_WELLNESS';
    case General = 'GENERAL';
}
