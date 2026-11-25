<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Enums;

enum PriceType: string
{
    use Concerns\EnumValues;

    case Recommended = 'recommended';
    case Maximum = 'maximum';
}
