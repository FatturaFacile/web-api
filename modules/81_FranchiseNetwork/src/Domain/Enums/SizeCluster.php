<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Enums;

enum SizeCluster: string
{
    use Concerns\EnumValues;

    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';
}
