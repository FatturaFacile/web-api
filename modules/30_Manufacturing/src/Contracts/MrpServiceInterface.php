<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Contracts;

use Modules\Manufacturing\DTOs\RunMrpDTO;
use Modules\Manufacturing\Models\MfgMrpPlannedOrder;
use Modules\Manufacturing\Models\MfgMrpRun;
use Modules\Manufacturing\Models\MfgProductionOrder;

/**
 * Material requirements planning.
 */
interface MrpServiceInterface
{
    /**
     * Runs MRP over the horizon: nets gross requirements (open sales order lines, forecasts not consumed by them,
     * safety stock, components of open and planned production orders) against stock and scheduled receipts, level by
     * level, and proposes make orders and purchase suggestions with their lot sizes and dates.
     */
    public function executeMrpRun(RunMrpDTO $dto): MfgMrpRun;

    /**
     * Converts a make planned order into a planned production order.
     */
    public function convertPlannedOrder(MfgMrpPlannedOrder $plannedOrder, ?string $actorUserId = null): MfgProductionOrder;

    /**
     * Records the procurement document a purchase suggestion was converted into, for the Procurement module.
     */
    public function markPurchaseSuggestionConverted(MfgMrpPlannedOrder $plannedOrder, string $documentType, string $documentId, ?string $actorUserId = null): MfgMrpPlannedOrder;
}
