<?php

declare(strict_types=1);

namespace Modules\Wms\Contracts;

use App\Exceptions\BusinessRuleException;

/**
 * What the warehouse management needs from stock, without knowing which module keeps it. The inventory module answers
 * through InventoryWmsStockGateway; another gateway can replace it. Implementations work on the application database
 * only and call no external system, so the WMS may call them inside its own transactions.
 */
interface WmsStockGateway
{
    /**
     * Moves the quantity of a stock item between two bins on behalf of a task and returns the stock movement
     * reference. Idempotent per task: when the task already has its movement, that reference is returned and nothing
     * is posted again.
     *
     * @throws BusinessRuleException when the source bin does not hold the quantity or a bin is frozen by a count
     */
    public function transfer(string $taskId, string $stockItemId, string $sourceBinId, string $destinationBinId, string $quantity, ?string $userId): string;

    /**
     * The bin where received goods wait for their putaway: the default bin of the warehouse.
     */
    public function receivingBinId(string $warehouseId): string;
}
