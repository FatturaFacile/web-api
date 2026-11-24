<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Models\StockMovement;

final readonly class ExecuteDoubleEntryMovementAction
{
    public function __construct(
        private DoubleEntryMovementServiceInterface $service
    ) {}

    public function execute(StockTransferDTO $dto): StockMovement
    {
        return $this->service->executeTransfer($dto);
    }
}
