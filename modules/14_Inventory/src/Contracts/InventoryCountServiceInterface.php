<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\DTOs\InventoryCountSessionDTO;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\InventoryCountSession;

interface InventoryCountServiceInterface
{
    public function createSession(InventoryCountSessionDTO $dto): InventoryCountSession;

    public function recordCount(string $sessionLineId, float $countedQuantity, string $userId): void;

    public function reconcileSession(InventoryCountSession $session, string $userId): InventoryAdjustment;
}
