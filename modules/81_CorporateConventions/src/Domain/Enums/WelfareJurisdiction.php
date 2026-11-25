<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\Enums;

use Modules\CorporateConventions\Domain\Enums\Concerns\EnumValues;

enum WelfareJurisdiction: string
{
    use EnumValues;

    case ItArt51Tuir = 'IT_ART51_TUIR';
    case ChRekaLunchcheck = 'CH_REKA_LUNCHCHECK';
}
