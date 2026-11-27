<?php

declare(strict_types=1);

namespace Modules\Distribution\Domain\Enums;

use Modules\Distribution\Domain\Enums\Concerns\EnumValues;

enum TransportMode: string
{
    use EnumValues;

    case Road = 'road';
    case Rail = 'rail';
    case Air = 'air';
    case Sea = 'sea';
    case Courier = 'courier';
    case Internal = 'internal';
}
