<?php

declare(strict_types=1);

namespace Modules\Quoting\Domain\Enums;

enum ConversionTarget: string
{
    case SalesOrder = 'sales_order';
    case Invoice = 'invoice';
}
