<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Enums;

/** A scheduled receipt: open purchase order, open production order or stock in transit. */
enum SupplyType: string
{
    case OpenPurchaseOrder = 'open_purchase_order';
    case OpenProductionOrder = 'open_production_order';
    case InTransit = 'in_transit';
}
