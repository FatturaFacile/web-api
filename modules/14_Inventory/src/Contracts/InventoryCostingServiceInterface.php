<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\DTOs\InboundCostingDTO;
use Modules\Inventory\DTOs\InboundCostingResult;
use Modules\Inventory\DTOs\OutboundCostingDTO;
use Modules\Inventory\DTOs\OutboundCostingResult;
use Modules\Inventory\Enums\ValuationMethod;
use Modules\Inventory\Models\InventoryCostLayer;
use Modules\Inventory\Models\StockItem;

interface InventoryCostingServiceInterface
{
    /**
     * Item override, else category, else weighted average.
     */
    public function resolveEffectiveMethod(StockItem $item): ValuationMethod;

    /**
     * @return array{category_method: string|null, item_method: string|null, effective_method: string, source: string}
     */
    public function describeMethod(StockItem $item): array;

    /**
     * Records an inbound movement: creates a cost layer, updates the moving average cost and,
     * for standard-cost items, records the purchase price variance.
     */
    public function recordInbound(StockItem $item, InboundCostingDTO $dto): InboundCostingResult;

    /**
     * Values an outbound movement per the effective method, depleting cost layers (FIFO order).
     */
    public function consumeOutbound(StockItem $item, OutboundCostingDTO $dto): OutboundCostingResult;

    /**
     * Current inventory value (2 decimals) of the item's on-hand stock under $method (default: effective).
     */
    public function valueOnHand(StockItem $item, ?ValuationMethod $method = null): string;

    /**
     * Cost (2 decimals) of depleting the next $quantity units from the open layers, without persisting.
     */
    public function previewFifoCost(StockItem $item, string $quantity): string;

    /**
     * Creates an opening-balance layer for on-hand stock not yet covered by cost layers (idempotent).
     */
    public function ensureOpeningLayers(StockItem $item, ?string $onHand = null): ?InventoryCostLayer;

    /**
     * @return array{inventory: string|null, cogs: string|null, ppv: string|null, landed_cost_clearing: string|null}
     */
    public function accountOverrides(StockItem $item): array;
}
