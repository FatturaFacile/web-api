<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Domain\Enums;

use Modules\CustomerSatisfaction\Domain\Enums\Concerns\EnumValues;

enum RecoveryPriority: string
{
    use EnumValues;

    case High = 'high';
    case Urgent = 'urgent';
}
