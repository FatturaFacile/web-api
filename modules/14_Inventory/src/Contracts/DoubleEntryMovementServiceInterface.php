<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Models\StockMovement;

interface DoubleEntryMovementServiceInterface
{
    public function executeTransfer(StockTransferDTO $dto): StockMovement;
}
