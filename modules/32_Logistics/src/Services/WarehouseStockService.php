<?php

declare(strict_types=1);

namespace Modules\Logistics\Services;

use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Warehouse;
use Modules\Logistics\Enums\LegalOwnerType;
use Modules\Logistics\Enums\WarehouseType;
use Modules\Logistics\Models\WarehouseStockLevel;

/**
 * Product-level stock per warehouse (the "StockLevel" ledger used by transfers and consignment) plus the
 * double-entry rows written to `stock_movements`. Legal ownership vs physical location is carried by the
 * warehouse (`legal_owner_type`), never by the movement itself.
 */
final class WarehouseStockService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Move stock between two warehouses (either side may be null for virtual sources/sinks, e.g. write-offs).
     */
    public function move(
        string $productId,
        ?string $fromWarehouseId,
        ?string $toWarehouseId,
        float $quantity,
        string $movementType,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $notes = null,
        ?float $unitCost = null,
    ): void {
        if ($quantity <= 0.0) {
            throw new DomainException('Movement quantity must be greater than zero.');
        }
        if ($fromWarehouseId === null && $toWarehouseId === null) {
            throw new DomainException('A stock movement needs a source or a destination warehouse.');
        }

        DB::transaction(function () use ($productId, $fromWarehouseId, $toWarehouseId, $quantity, $movementType, $referenceType, $referenceId, $notes, $unitCost): void {
            if ($fromWarehouseId !== null) {
                $level = $this->lockedLevel($fromWarehouseId, $productId);
                if ($level === null || (float) $level->quantity_on_hand < $quantity) {
                    $available = $level !== null ? (float) $level->quantity_on_hand : 0.0;
                    throw new DomainException("Insufficient stock for product {$productId} in warehouse {$fromWarehouseId}: required {$quantity}, available {$available}.");
                }
                $level->quantity_on_hand = round((float) $level->quantity_on_hand - $quantity, 4);
                $level->save();
                $this->recordMovement($productId, $fromWarehouseId, -$quantity, $movementType.'_out', $referenceType, $referenceId, $notes, $unitCost);
            }

            if ($toWarehouseId !== null) {
                $level = $this->lockedLevel($toWarehouseId, $productId, create: true);
                $level->quantity_on_hand = round((float) $level->quantity_on_hand + $quantity, 4);
                $level->save();
                $this->recordMovement($productId, $toWarehouseId, $quantity, $movementType.'_in', $referenceType, $referenceId, $notes, $unitCost);
            }
        });
    }

    /**
     * Load opening stock (used by setup flows and tests); recorded as a regular inbound movement.
     */
    public function receiveOpeningStock(string $warehouseId, string $productId, float $quantity, ?float $unitCost = null): void
    {
        $this->move($productId, null, $warehouseId, $quantity, 'opening_stock', notes: 'Opening stock', unitCost: $unitCost);
    }

    public function onHand(string $warehouseId, string $productId): float
    {
        $level = WarehouseStockLevel::query()->where('warehouse_id', $warehouseId)->where('product_id', $productId)->first();

        return $level !== null ? (float) $level->quantity_on_hand : 0.0;
    }

    /**
     * Finds (or lazily creates) the tenant's virtual in-transit warehouse.
     */
    public function resolveTransitWarehouse(?string $transitWarehouseId = null): Warehouse
    {
        if ($transitWarehouseId !== null) {
            $warehouse = Warehouse::query()->findOrFail($transitWarehouseId);
            if ($warehouse->warehouse_type !== WarehouseType::Transit->value) {
                throw new DomainException('The selected transit warehouse is not of type transit.');
            }

            return $warehouse;
        }

        $existing = Warehouse::query()->where('warehouse_type', WarehouseType::Transit->value)->orderBy('created_at')->first();
        if ($existing !== null) {
            return $existing;
        }

        return Warehouse::create([
            'tenant_id' => $this->tenantContext->getTenantId(),
            'code' => 'TRANSIT',
            'name' => 'In transito',
            'warehouse_type' => WarehouseType::Transit->value,
            'legal_owner_type' => LegalOwnerType::Company->value,
            'is_primary' => false,
        ]);
    }

    private function lockedLevel(string $warehouseId, string $productId, bool $create = false): ?WarehouseStockLevel
    {
        $query = fn () => WarehouseStockLevel::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        $level = $query();
        if ($level === null && $create) {
            WarehouseStockLevel::create([
                'tenant_id' => $this->tenantContext->getTenantId(),
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'quantity_on_hand' => 0,
            ]);
            $level = $query();
        }

        return $level;
    }

    private function recordMovement(string $productId, string $warehouseId, float $signedQty, string $movementType, ?string $referenceType, ?string $referenceId, ?string $notes, ?float $unitCost): void
    {
        StockMovement::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenantContext->getTenantId(),
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'type' => 'transfer',
            'movement_type' => $movementType,
            'quantity' => abs($signedQty),
            'unit_cost' => $unitCost ?? 0,
            'total_valuation' => round(abs($signedQty) * ($unitCost ?? 0), 2),
            'origin_document_type' => $referenceType,
            'origin_document_id' => $referenceId,
            'executed_by_user_id' => auth()->id(),
            'executed_at' => Carbon::now(),
            'notes' => $notes,
        ]);
    }
}
