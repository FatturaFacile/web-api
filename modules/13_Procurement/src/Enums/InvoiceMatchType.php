<?php

declare(strict_types=1);

namespace Modules\Procurement\Enums;

/**
 * How a supplier invoice is matched: against the order only (services, consulting) or against the order and the goods
 * received.
 */
enum InvoiceMatchType: string
{
    case TwoWay = '2_way';
    case ThreeWay = '3_way';
}
