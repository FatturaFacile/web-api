<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\DTOs\InventoryCountSessionDTO;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\InventoryCountLine;
use Modules\Inventory\Models\InventoryCountSession;

/**
 * Physical counts: open a session with the system quantities of what is counted, record counts (and recounts), then
 * approve the differences, which posts an inventory adjustment through the movement service and the ledger.
 */
interface InventoryCountServiceInterface
{
    public function createSession(InventoryCountSessionDTO $dto): InventoryCountSession;

    /**
     * Records the counted quantity of a line; with a session id the line must belong to that session. A resend with a
     * known client entry id changes nothing; a variance above the recount thresholds sends the line back for a recount.
     */
    public function recordCount(string $sessionLineId, float|string $countedQuantity, string $userId, ?string $sessionId = null, ?string $clientEntryId = null, ?string $notes = null, ?string $deviceId = null): InventoryCountLine;

    public function reconcileSession(InventoryCountSession $session, string $userId): InventoryAdjustment;
}
