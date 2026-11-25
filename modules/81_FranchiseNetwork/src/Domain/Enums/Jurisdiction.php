<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Enums;

enum Jurisdiction: string
{
    use Concerns\EnumValues;

    case It = 'IT';
    case Ch = 'CH';
}
