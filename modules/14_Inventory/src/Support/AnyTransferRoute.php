<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use Modules\Inventory\Contracts\TransferRouteValidator;
use Modules\Inventory\Models\Warehouse;

/** Default route rule of the inventory module: a transfer may go between any two different warehouses. */
final class AnyTransferRoute implements TransferRouteValidator
{
    public function assertValid(string $purpose, Warehouse $source, Warehouse $destination): void {}
}
