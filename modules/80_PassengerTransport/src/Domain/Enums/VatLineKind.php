<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\Enums;

use Modules\PassengerTransport\Domain\Enums\Concerns\EnumValues;

enum VatLineKind: string
{
    use EnumValues;

    case ItDomestic = 'it_domestic';
    case ItExemptArt10N14 = 'it_exempt_art10_n14';
    case ItForeignLeg = 'it_foreign_leg';
    case ChDomestic = 'ch_domestic';
    case ChForeignLeg = 'ch_foreign_leg';
    case ChExemptArt23 = 'ch_exempt_art23';
}
