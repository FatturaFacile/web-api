<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Enums;

enum PushType: string
{
    use Concerns\EnumValues;

    case Catalog = 'catalog';
    case PriceList = 'price_list';
    case Promotion = 'promotion';
    case LoyaltyCampaign = 'loyalty_campaign';
}
