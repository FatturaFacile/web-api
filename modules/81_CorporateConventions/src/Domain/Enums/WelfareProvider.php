<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\Enums;

use Modules\CorporateConventions\Domain\Enums\Concerns\EnumValues;

enum WelfareProvider: string
{
    use EnumValues;

    case Edenred = 'EDENRED';
    case Pellegrini = 'PELLEGRINI';
    case PluxeeSodexo = 'PLUXEE_SODEXO';
    case Eudaimon = 'EUDAIMON';
    case Doubleyou = 'DOUBLEYOU';
    case RekaCard = 'REKA_CARD';
    case LunchCheck = 'LUNCH_CHECK';
    case Boncadeau = 'BONCADEAU';
    case Other = 'OTHER';
}
