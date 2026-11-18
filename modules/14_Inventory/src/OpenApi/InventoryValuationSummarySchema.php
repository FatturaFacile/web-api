<?php

declare(strict_types=1);

namespace Modules\Inventory\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'InventoryValuationSummary',
    title: 'Valuation Balance Summary',
    description: 'Warehouse inventory valuation aggregated by item and costing method',
    required: ['warehouse_id', 'valuation_method', 'total_inventory_value']
)]
class InventoryValuationSummarySchema
{
    #[OA\Property(format: 'uuid')]
    public string $warehouse_id;

    #[OA\Property(example: 'FIFO')]
    public string $valuation_method;

    #[OA\Property(example: 184500.50)]
    public float $total_inventory_value;

    #[OA\Property(example: 450)]
    public int $total_active_skus;
}
