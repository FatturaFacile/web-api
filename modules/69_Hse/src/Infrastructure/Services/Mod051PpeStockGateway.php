<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Services;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Hse\Domain\Contracts\PpeStockGatewayInterface;
use Modules\Hse\Domain\Exceptions\HseException;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Support\Quantity;
use stdClass;

/**
 * Warehouse Management (Mod 051) adapter: the PPE product maps to a stock item, the stock sits in the bins of the
 * warehouse (zone, aisle, rack, bin) and leaves it through a "dispatch" movement per bin, which locks the quant and
 * never lets it go below zero.
 */
final class Mod051PpeStockGateway implements PpeStockGatewayInterface
{
    public function __construct(
        private readonly DoubleEntryMovementServiceInterface $movements,
        private readonly TenantContext $tenantContext,
    ) {}

    public function available(string $productId, string $warehouseId, ?string $lotNumber): float
    {
        return (float) $this->quants($productId, $warehouseId, $lotNumber)->sum(fn (stdClass $quant): float => (float) $quant->available);
    }

    public function issue(string $productId, string $warehouseId, ?string $lotNumber, float $quantity, string $originDocumentId, ?string $userId): string
    {
        if ($this->available($productId, $warehouseId, $lotNumber) + 1e-9 < $quantity) {
            throw new HseException('Not enough stock of the PPE in the warehouse.');
        }

        $remaining = Quantity::of($quantity);
        $firstMovement = null;

        try {
            foreach ($this->quants($productId, $warehouseId, $lotNumber) as $quant) {
                if (! Quantity::isPositive($remaining)) {
                    break;
                }

                $take = Quantity::compare(Quantity::of((float) $quant->available), $remaining) < 0 ? Quantity::of((float) $quant->available) : $remaining;
                $movement = $this->movements->executeTransfer(new StockTransferDTO(
                    stockItemId: (string) $quant->stock_item_id,
                    sourceBinId: (string) $quant->warehouse_bin_id,
                    destinationBinId: null,
                    quantity: (float) $take,
                    movementType: 'dispatch',
                    lotId: $quant->lot_id !== null ? (string) $quant->lot_id : null,
                    originDocumentType: 'hse_ppe_distribution',
                    originDocumentId: $originDocumentId,
                    userId: $userId,
                    notes: 'PPE hand-over',
                ));
                $firstMovement ??= (string) $movement->id;
                $remaining = Quantity::sub($remaining, $take);
            }
        } catch (StockRuleViolation $violation) {
            throw new HseException($violation->getMessage(), 0, $violation);
        }

        return $firstMovement ?? throw new HseException('Nothing was issued from the warehouse.');
    }

    /**
     * @return Collection<int, stdClass>
     */
    private function quants(string $productId, string $warehouseId, ?string $lotNumber): Collection
    {
        return DB::table('stock_quants as q')
            ->join('stock_items as i', 'i.id', '=', 'q.stock_item_id')
            ->join('warehouse_bins as b', 'b.id', '=', 'q.warehouse_bin_id')
            ->join('warehouse_racks as r', 'r.id', '=', 'b.warehouse_rack_id')
            ->join('warehouse_aisles as a', 'a.id', '=', 'r.warehouse_aisle_id')
            ->join('warehouse_zones as z', 'z.id', '=', 'a.warehouse_zone_id')
            ->leftJoin('stock_lots as l', 'l.id', '=', 'q.lot_id')
            ->where('q.tenant_id', $this->tenantContext->getTenantId())
            ->where('i.product_id', $productId)
            ->where('z.warehouse_id', $warehouseId)
            ->whereNull('q.deleted_at')
            ->when($lotNumber !== null, fn ($query) => $query->where('l.lot_number', $lotNumber))
            ->whereRaw('(q.quantity_on_hand - q.quantity_reserved) > 0')
            ->selectRaw('q.stock_item_id, q.warehouse_bin_id, q.lot_id, (q.quantity_on_hand - q.quantity_reserved) as available')
            ->orderByDesc('available')
            ->get();
    }
}
