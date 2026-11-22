<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Enums;

/** Where a requirement comes from: MPS line (Mod 062), sales order line (Mod 037), safety stock (Mod 051), allocated stock, production order components (Mod 064) or the parent proposal. */
enum DemandSourceType: string
{
    case MpsLine = 'mps_line';
    case SalesOrderLine = 'sales_order_line';
    case SafetyStock = 'safety_stock';
    case Allocation = 'allocated_stock';
    case ProductionOrder = 'production_order';
    case MrpProposal = 'mrp_proposal';
}
