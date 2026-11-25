<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\Enums;

use Modules\CorporateConventions\Domain\Enums\Concerns\EnumValues;

enum WelfareCategory: string
{
    use EnumValues;

    case Education = 'EDUCATION';
    case Healthcare = 'HEALTHCARE';
    case SportsFitness = 'SPORTS_FITNESS';
    case CultureLeisure = 'CULTURE_LEISURE';
    case ShoppingVoucherExempt = 'SHOPPING_VOUCHER_EXEMPT';
    case MealVoucher = 'MEAL_VOUCHER';
}
