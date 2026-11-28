<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Services;

use App\Models\Product;
use Modules\Beekeeping\Exceptions\BeekeepingException;
use Modules\Beekeeping\Models\JarringRun;
use Modules\Inventory\Services\ProductStockLedger;

/**
 * Deducts the jars, caps and labels of a jarring run from the warehouse (one of each per jar) through the inventory stock ledger.
 * The deduction is skipped when the run names no warehouse or no packaging product.
 */
final class PackagingStockConsumer implements PackagingStock
{
    public function __construct(private readonly ProductStockLedger $ledger) {}

    /**
     * @return list<string> ids of the stock movements booked
     */
    public function consume(JarringRun $run): array
    {
        if ($run->warehouse_id === null) {
            return [];
        }

        $movements = [];

        foreach (['jar_product_id' => 'jar', 'cap_product_id' => 'cap', 'label_product_id' => 'label'] as $column => $role) {
            $productId = $run->{$column};

            if ($productId === null) {
                continue;
            }

            $product = Product::query()->find($productId);

            if ($product === null) {
                throw new BeekeepingException('A packaging product does not exist.', 'packaging_product_not_found', 404, ['role' => $role]);
            }

            $movement = $this->ledger->issue($product, (string) $run->warehouse_id, (float) $run->jars_count, [
                'movement_type' => 'beekeeping_jarring_out',
                'app_type' => 'out',
                'reference_type' => 'bee_jarring_run',
                'reference_id' => $run->id,
                'notes' => 'Jarring run '.$run->jar_lot_code.' ('.$role.')',
            ]);
            $movements[] = (string) $movement->id;
        }

        return $movements;
    }
}
