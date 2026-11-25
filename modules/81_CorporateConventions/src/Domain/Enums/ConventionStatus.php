<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\Enums;

use Modules\CorporateConventions\Domain\Enums\Concerns\EnumValues;

enum ConventionStatus: string
{
    use EnumValues;

    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Expired = 'EXPIRED';
}
