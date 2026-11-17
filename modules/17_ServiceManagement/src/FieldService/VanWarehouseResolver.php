<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService;

use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use Modules\ServiceManagement\Models\ServiceTechnician;

/**
 * Finds the mobile van warehouse (`warehouse_type = vehicle_van`) assigned to a technician's user and reads its stock
 * through the inventory service, so field service never keeps a stock truth of its own.
 */
final readonly class VanWarehouseResolver
{
    public const VanType = 'vehicle_van';

    public function __construct(private StockService $stock) {}

    public function forTechnician(ServiceTechnician $technician): ?Warehouse
    {
        if ($technician->user_id === null) {
            return null;
        }

        return Warehouse::query()
            ->where('tenant_id', $technician->tenant_id)
            ->where('warehouse_type', self::VanType)
            ->where('assigned_user_id', $technician->user_id)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->first();
    }

    /**
     * Available quantity per product id in the warehouse.
     *
     * @return array<string, float>
     */
    public function availableByProduct(Warehouse $warehouse): array
    {
        $levels = [];

        foreach ($this->stock->levels((string) $warehouse->id) as $row) {
            $levels[$row['product_id']] = (float) $row['available'];
        }

        return $levels;
    }

    /**
     * The parts a work order needs that the van cannot cover: product id => missing quantity.
     *
     * @param  list<array{product_id: string, quantity: int|float|string}>  $requiredParts
     * @return array<string, float>
     */
    public function shortfall(?Warehouse $van, array $requiredParts): array
    {
        $missing = [];
        $available = $van === null ? [] : $this->availableByProduct($van);

        foreach ($requiredParts as $part) {
            $productId = (string) $part['product_id'];
            $need = (float) $part['quantity'] - ($available[$productId] ?? 0.0);

            if ($need > 0.00001) {
                $missing[$productId] = round(($missing[$productId] ?? 0.0) + $need, 4);
            }
        }

        return $missing;
    }
}
