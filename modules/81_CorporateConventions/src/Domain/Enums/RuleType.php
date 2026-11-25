<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\Enums;

use Modules\CorporateConventions\Domain\Enums\Concerns\EnumValues;

enum RuleType: string
{
    use EnumValues;

    case PriceList = 'PRICE_LIST';
    case CategoryPercentage = 'CATEGORY_PERCENTAGE';
    case ProductFamilyPercentage = 'PRODUCT_FAMILY_PERCENTAGE';
    case GlobalPercentage = 'GLOBAL_PERCENTAGE';
}
