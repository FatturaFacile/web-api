<?php

declare(strict_types=1);

namespace Modules\Intrastat\Enums;

enum SupplyKind: string
{
    case Goods = 'goods';
    case Services = 'services';
}
