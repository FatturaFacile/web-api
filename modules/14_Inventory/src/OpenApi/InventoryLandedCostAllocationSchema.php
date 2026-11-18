<?php

declare(strict_types=1);

namespace Modules\Inventory\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'InventoryLandedCostAllocation',
    title: 'Landed Cost Allocation',
    required: ['receipt_id', 'item_id', 'basis_value', 'allocated_amount', 'to_inventory_amount', 'to_cogs_amount']
)]
class InventoryLandedCostAllocationSchema
{
    #[OA\Property(format: 'uuid')]
    public string $receipt_id;

    #[OA\Property(format: 'uuid')]
    public string $item_id;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $lot_id;

    #[OA\Property(example: '1000.000000')]
    public string $basis_value;

    #[OA\Property(example: '200.00')]
    public string $allocated_amount;

    #[OA\Property(example: '120.00')]
    public string $to_inventory_amount;

    #[OA\Property(example: '80.00')]
    public string $to_cogs_amount;

    #[OA\Property(example: '0.00', description: 'Standard-cost items: share expensed as purchase price variance')]
    public string $to_variance_amount;
}
