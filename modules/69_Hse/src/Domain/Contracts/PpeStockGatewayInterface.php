<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Contracts;

/**
 * Warehouse Management (Mod 051): stock lookup and the issue of PPE to a worker.
 */
interface PpeStockGatewayInterface
{
    /**
     * Quantity available (on hand minus reserved) of the product in the warehouse, for the lot when given.
     */
    public function available(string $productId, string $warehouseId, ?string $lotNumber): float;

    /**
     * Issues the quantity out of the warehouse (by lot when given) and returns the id of the stock movement.
     * Throws HseException when the stock is not enough.
     */
    public function issue(string $productId, string $warehouseId, ?string $lotNumber, float $quantity, string $originDocumentId, ?string $userId): string;
}
