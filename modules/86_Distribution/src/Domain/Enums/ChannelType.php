<?php

declare(strict_types=1);

namespace Modules\Distribution\Domain\Enums;

use Modules\Distribution\Domain\Enums\Concerns\EnumValues;

enum ChannelType: string
{
    use EnumValues;

    case B2bPortal = 'b2b_portal';
    case Ecommerce = 'ecommerce';
    case RetailPos = 'retail_pos';
    case Marketplace = 'marketplace';
    case DirectSales = 'direct_sales';
    case Custom = 'custom';
}
