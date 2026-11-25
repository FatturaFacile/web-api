<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\Enums;

use Modules\CorporateConventions\Domain\Enums\Concerns\EnumValues;

enum Relationship: string
{
    use EnumValues;

    case Own = 'SELF';
    case Spouse = 'SPOUSE';
    case Child = 'CHILD';
    case Parent = 'PARENT';
}
