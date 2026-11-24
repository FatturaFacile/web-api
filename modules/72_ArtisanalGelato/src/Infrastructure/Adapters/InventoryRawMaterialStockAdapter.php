<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Infrastructure\Adapters;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\ArtisanalGelato\Domain\Contracts\RawMaterialStockPort;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\StockAllocationPlanner;

/**
 * Deducts gelato raw materials through the inventory module's double-entry movement service, quant by quant in the
 * inventory's picking order (FEFO), from the primary warehouse of the company. Missing stock is refused.
 */
final readonly class InventoryRawMaterialStockAdapter implements RawMaterialStockPort
{
    public const string OriginType = 'gelato_lot';

    public function __construct(
        private DoubleEntryMovementServiceInterface $movements,
        private StockAllocationPlanner $planner,
    ) {}

    public function issue(string $tenantId, string $stockItemId, float $quantity, ?string $stockLotNumber, string $gelatoLotId, ?string $userId): void
    {
        $stockItem = StockItem::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')->find($stockItemId)
            ?? throw new InvalidArgumentException('The inventory item linked to the ingredient does not exist.');

        $warehouse = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')->orderByDesc('is_primary')->orderBy('created_at')->first()
            ?? throw new InvalidArgumentException('No warehouse of the company is available for the raw material deduction.');

        $lotId = null;

        if ($stockLotNumber !== null && $stockLotNumber !== '') {
            $found = StockLot::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('stock_item_id', $stockItemId)->where('lot_number', $stockLotNumber)->value('id')
                ?? throw new InvalidArgumentException("Raw material lot [{$stockLotNumber}] is unknown in inventory.");
            $lotId = (string) $found;
        }

        DB::transaction(function () use ($stockItem, $warehouse, $quantity, $lotId, $gelatoLotId, $userId): void {
            $quants = $this->planner->lockEligibleQuants($stockItem, $warehouse, $lotId);
            ['shares' => $shares, 'short' => $short] = $this->planner->take($quants, number_format($quantity, 4, '.', ''));

            if (bccomp($short, '0', 4) > 0) {
                throw new InvalidArgumentException("Not enough stock of [{$stockItem->sku}] in warehouse [{$warehouse->name}]: short by ".bcadd($short, '0', 4).'.');
            }

            foreach ($shares as $share) {
                try {
                    $this->movements->executeTransfer(new StockTransferDTO(
                        stockItemId: (string) $stockItem->id,
                        sourceBinId: (string) $share['quant']->warehouse_bin_id,
                        destinationBinId: null,
                        quantity: (float) $share['quantity'],
                        movementType: 'production_issue',
                        lotId: $share['quant']->lot_id !== null ? (string) $share['quant']->lot_id : null,
                        originDocumentType: self::OriginType,
                        originDocumentId: $gelatoLotId,
                        userId: $userId,
                        notes: 'Gelato lot raw material consumption',
                    ));
                } catch (StockRuleViolation $violation) {
                    throw new InvalidArgumentException($violation->getMessage(), 0, $violation);
                }
            }
        });
    }
}
