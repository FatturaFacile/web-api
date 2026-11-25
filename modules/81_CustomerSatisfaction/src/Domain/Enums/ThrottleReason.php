<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Domain\Enums;

use Modules\CustomerSatisfaction\Domain\Enums\Concerns\EnumValues;

enum ThrottleReason: string
{
    use EnumValues;

    case CooldownActive = 'cooldown_active';
    case OptOut = 'opt_out';
    case MissingContact = 'missing_contact';
    case InvalidEvent = 'invalid_event';
}
