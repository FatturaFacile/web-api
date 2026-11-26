<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Domain\Enums;

enum PackagingRole: string
{
    use EnumValues;

    case Purchase = 'purchase';
    case Sales = 'sales';
    case Logistic = 'logistic';
}
