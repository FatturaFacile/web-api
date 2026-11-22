<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Contracts;

use Carbon\CarbonImmutable;

/**
 * Port towards the modules that receive released proposals: procurement (Mod 044 purchase requisitions, Mod 046 purchase
 * orders are raised from them) and production (Mod 064 production orders).
 */
interface ReleaseGatewayInterface
{
    /**
     * Creates a draft purchase requisition with one line per entry and returns its id.
     *
     * @param  list<array{description: string, quantity: string, uom: string, unit_price: string, supplier_id: string|null}>  $lines
     */
    public function createPurchaseRequisition(string $tenantId, string $requestorUserId, string $title, CarbonImmutable $requiredBy, array $lines, string $notes): string;

    /**
     * Creates a planned production order and returns its id.
     */
    public function createProductionOrder(string $tenantId, string $itemId, string $quantity, CarbonImmutable $plannedStart, CarbonImmutable $plannedEnd, ?string $bomId, ?string $createdBy): string;
}
