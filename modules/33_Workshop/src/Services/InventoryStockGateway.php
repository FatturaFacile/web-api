<?php

declare(strict_types=1);

namespace Modules\Workshop\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Services\Inventory\StockService;
use Modules\Inventory\Contracts\StockReservationServiceInterface;
use Modules\Inventory\DTOs\StockReservationDTO;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\ProductStockItemResolver;
use Modules\Inventory\Services\StockAllocationPlanner;
use Modules\Workshop\Contracts\WorkshopStockGateway;
use Modules\Workshop\Models\WorkshopFolder;

/**
 * The workshop stock gateway backed by the inventory module: reservations through its reservation contract, issues
 * through the application stock service that writes the double-entry movement.
 */
final readonly class InventoryStockGateway implements WorkshopStockGateway
{
    public const string ReservedForType = 'workshop_part';

    /** Days a reservation lives before the inventory module expires it. */
    private const int ReservationDays = 90;

    public function __construct(
        private StockReservationServiceInterface $reservations,
        private ProductStockItemResolver $stockItems,
        private StockAllocationPlanner $planner,
        private StockService $stock,
    ) {}

    public function isTracked(string $productId): bool
    {
        return Product::query()->whereKey($productId)->where('track_inventory', true)->exists();
    }

    public function availableQuantity(string $productId): string
    {
        $product = Product::query()->find($productId);
        $default = $this->stock->defaultWarehouse();
        $warehouse = $default !== null ? Warehouse::query()->find($default->id) : null;

        if ($product === null || $warehouse === null) {
            return '0.0000';
        }

        $item = $this->stockItems->find((string) $product->id, (string) $product->tenant_id);

        if ($item === null) {
            return '0.0000';
        }

        $total = '0';

        foreach ($this->planner->availability($item, $warehouse) as $quant) {
            $total = bcadd($total, $quant['quantity_available'], 4);
        }

        return bcadd($total, '0', 4);
    }

    public function reserve(string $productId, string $quantity, string $consumptionId): string
    {
        $product = Product::query()->findOrFail($productId);
        $warehouse = $this->stock->defaultWarehouse();

        if ($warehouse === null) {
            throw new BusinessRuleException('The company has no warehouse to reserve stock in.');
        }

        try {
            $reservation = $this->reservations->allocate(new StockReservationDTO(
                stockItemId: (string) $this->stockItems->resolve($product)->id,
                warehouseId: (string) $warehouse->id,
                reservedForType: self::ReservedForType,
                reservedForId: $consumptionId,
                quantity: (float) $quantity,
                expiresAt: now()->addDays(self::ReservationDays)->toDateTimeString(),
                userId: auth()->id() !== null ? (string) auth()->id() : null,
            ));
        } catch (StockRuleViolation $violation) {
            throw new BusinessRuleException($violation->getMessage());
        }

        return (string) $reservation->id;
    }

    public function release(string $reservationReference): void
    {
        $reservation = StockReservation::query()->find($reservationReference);

        if ($reservation !== null) {
            $this->reservations->release($reservation);
        }
    }

    public function issue(string $productId, string $quantity, string $folderId, ?string $reservationReference): string
    {
        if ($reservationReference !== null) {
            $this->release($reservationReference);
        }

        try {
            $movement = $this->stock->record($productId, 'out', (float) $quantity, WorkshopFolder::query()->findOrFail($folderId), null, 0.0, 'Workshop part');
        } catch (StockRuleViolation $violation) {
            throw new BusinessRuleException($violation->getMessage());
        }

        if ($movement === null) {
            throw new BusinessRuleException('The company has no warehouse to issue stock from.');
        }

        return (string) $movement->id;
    }
}
