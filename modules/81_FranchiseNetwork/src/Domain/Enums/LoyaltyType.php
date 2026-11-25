<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Enums;

enum LoyaltyType: string
{
    use Concerns\EnumValues;

    case Earn = 'earn';
    case Redeem = 'redeem';
}
