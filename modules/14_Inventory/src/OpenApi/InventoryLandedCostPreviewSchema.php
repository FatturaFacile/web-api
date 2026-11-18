<?php

declare(strict_types=1);

namespace Modules\Inventory\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'InventoryLandedCostPreview',
    title: 'Landed Cost Allocation Preview',
    required: ['allocations', 'totals']
)]
class InventoryLandedCostPreviewSchema
{
    #[OA\Property(type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryLandedCostAllocation'))]
    public array $allocations;

    #[OA\Property(type: 'object', properties: [
        new OA\Property(property: 'allocated', type: 'string', example: '300.00'),
        new OA\Property(property: 'difference', type: 'string', example: '0.00'),
    ])]
    public array $totals;
}
