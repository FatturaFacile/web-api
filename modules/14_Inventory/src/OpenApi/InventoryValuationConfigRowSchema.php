<?php

declare(strict_types=1);

namespace Modules\Inventory\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'InventoryValuationConfigRow',
    title: 'Item Valuation Configuration',
    description: 'Effective valuation method of an item: item override, else category default, else weighted average',
    required: ['item_id', 'sku', 'effective_method', 'source']
)]
class InventoryValuationConfigRowSchema
{
    #[OA\Property(format: 'uuid')]
    public string $item_id;

    #[OA\Property(example: 'SKU-001')]
    public string $sku;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $category_id;

    #[OA\Property(enum: ['fifo', 'weighted_average', 'standard_cost'], nullable: true)]
    public ?string $category_method;

    #[OA\Property(enum: ['fifo', 'weighted_average', 'standard_cost'], nullable: true)]
    public ?string $item_method;

    #[OA\Property(enum: ['fifo', 'weighted_average', 'standard_cost'])]
    public string $effective_method;

    #[OA\Property(enum: ['item', 'category', 'default'])]
    public string $source;
}
