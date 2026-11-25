<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\Enums;

use Modules\PassengerTransport\Domain\Enums\Concerns\EnumValues;

enum IssueSeverity: string
{
    use EnumValues;

    case Blocking = 'blocking';
    case Warning = 'warning';
}
