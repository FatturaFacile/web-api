<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\Warehouse;

/**
 * Business rules on the route of a transfer order (which warehouse types may send to which, inter-company rules...).
 * The inventory module accepts every route; the logistics module binds the rules of its transfer purposes.
 */
interface TransferRouteValidator
{
    /**
     * @throws StockRuleViolation when the purpose does not allow the route
     */
    public function assertValid(string $purpose, Warehouse $source, Warehouse $destination): void;
}
