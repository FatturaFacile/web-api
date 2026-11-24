<?php

declare(strict_types=1);

namespace Modules\Inventory\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'InventoryValuationItem',
    title: 'Item Valuation Settings',
    description: 'Response of PUT valuation/items/{id}; `revaluation` is set when on-hand stock was revalued',
    required: ['id', 'effective_method', 'source']
)]
class InventoryValuationItemSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(example: 'SKU-001')]
    public string $sku;

    #[OA\Property(enum: ['fifo', 'weighted_average', 'standard_cost'], nullable: true)]
    public ?string $valuation_method;

    #[OA\Property(enum: ['fifo', 'weighted_average', 'standard_cost'])]
    public string $effective_method;

    #[OA\Property(enum: ['item', 'category', 'default'])]
    public string $source;

    #[OA\Property(example: '5.0000')]
    public string $standard_cost;

    #[OA\Property(example: '6.0000')]
    public string $moving_average_cost;

    #[OA\Property(example: '1.250', nullable: true)]
    public ?string $weight_kg;

    #[OA\Property(example: '0.0100', nullable: true)]
    public ?string $volume_m3;

    #[OA\Property(type: 'object', nullable: true, properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'total_inventory_delta', type: 'string', example: '12.50'),
        new OA\Property(property: 'total_cogs_delta', type: 'string', example: '-12.50'),
        new OA\Property(property: 'journal_entry_id', type: 'string', format: 'uuid', nullable: true),
    ])]
    public ?array $revaluation;
}
