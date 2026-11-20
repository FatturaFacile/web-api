<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services;

use App\Models\Product;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\ProductStockLedger;

/**
 * Takes the spare parts consumed by a repair out of stock through the inventory ledger (the stock truth of catalog
 * products). Idempotent per RMA line: a line already booked is never booked twice, so a replayed event is harmless.
 */
final readonly class RmaPartsStockService
{
    public const string ReferenceType = 'esm_rma_line';

    public function __construct(private ProductStockLedger $ledger) {}

    /**
     * @param  list<array{rma_line_id: string, product_id: ?string, description?: string, qty: float, unit_cost?: float}>  $consumedParts
     * @return int number of lines booked now
     */
    public function consume(string $tenantId, string $rmaNumber, array $consumedParts): int
    {
        $warehouseId = Warehouse::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->value('id');

        if ($warehouseId === null) {
            return 0;
        }

        $booked = 0;

        foreach ($consumedParts as $part) {
            if ($part['product_id'] === null || $part['qty'] <= 0.0) {
                continue;
            }

            $already = StockMovement::query()->withoutGlobalScopes()
                ->where('reference_type', self::ReferenceType)
                ->where('reference_id', $part['rma_line_id'])
                ->exists();

            $product = Product::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($part['product_id'])->first();

            if ($already || $product === null) {
                continue;
            }

            $this->ledger->issue($product, (string) $warehouseId, $part['qty'], [
                'movement_type' => 'rma_consumption_out',
                'app_type' => 'out',
                'reference_type' => self::ReferenceType,
                'reference_id' => $part['rma_line_id'],
                'unit_cost' => $part['unit_cost'] ?? null,
                'notes' => 'Spare part consumed by RMA '.$rmaNumber,
            ]);
            $booked++;
        }

        return $booked;
    }
}
