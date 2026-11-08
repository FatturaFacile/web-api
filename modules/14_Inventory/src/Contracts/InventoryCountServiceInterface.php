<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\DTOs\InventoryCountSessionDTO;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\InventoryCountSession;

/**
 * Physical counts: open a session with the system quantities of what is counted, record counts (and recounts), then
 * approve the differences, which posts an inventory adjustment through the movement service and the ledger.
 */
interface InventoryCountServiceInterface
{
    public function createSession(InventoryCountSessionDTO $dto): InventoryCountSession;

    /**
     * Records the counted quantity of a line; with a session id the line must belong to that session.
     */
    public function recordCount(string $sessionLineId, float|string $countedQuantity, string $userId, ?string $sessionId = null): void;

    public function reconcileSession(InventoryCountSession $session, string $userId): InventoryAdjustment;
}
