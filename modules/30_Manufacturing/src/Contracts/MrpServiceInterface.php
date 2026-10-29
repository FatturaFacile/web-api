<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Contracts;

use Modules\Manufacturing\DTOs\RunMrpDTO;
use Modules\Manufacturing\Models\MfgMrpRun;

interface MrpServiceInterface
{
    /**
     * Executes Material Requirements Planning (MRP) run across items in the horizon.
     * Computes Gross Demand, Available Inventory, Scheduled Receipts, Safety Stock,
     * and Net Requirements using the canonical formula:
     * Net Requirement = Gross Demand - Available Inventory - Scheduled Receipts + Safety Stock.
     */
    public function executeMrpRun(RunMrpDTO $dto): MfgMrpRun;

    public function firmPlannedOrder(string $plannedOrderId): void;

    public function convertPlannedOrder(string $plannedOrderId): string;
}
