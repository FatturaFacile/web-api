<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Domain\Contracts;

use InvalidArgumentException;

/**
 * Port to the stock ledger that holds the raw materials of the gelato lots. The infrastructure adapter moves the quantity
 * out of the warehouse through the inventory module.
 */
interface RawMaterialStockPort
{
    /**
     * Deducts a quantity (in stock units of the item) of a stock item, optionally of one named stock lot, for a gelato lot.
     *
     * @throws InvalidArgumentException when the stock does not cover the quantity or the item cannot be resolved
     */
    public function issue(string $tenantId, string $stockItemId, float $quantity, ?string $stockLotNumber, string $gelatoLotId, ?string $userId): void;
}
