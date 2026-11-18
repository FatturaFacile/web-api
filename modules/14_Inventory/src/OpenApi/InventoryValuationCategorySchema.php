<?php

declare(strict_types=1);

namespace Modules\Inventory\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'InventoryValuationCategory',
    title: 'Category Valuation Settings',
    required: ['id', 'valuation_method']
)]
class InventoryValuationCategorySchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(example: 'CAT-RAW')]
    public string $code;

    #[OA\Property(example: 'Raw materials')]
    public string $name;

    #[OA\Property(enum: ['fifo', 'weighted_average', 'standard_cost'])]
    public string $valuation_method;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $inventory_account_id;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $cogs_account_id;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $ppv_account_id;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $landed_cost_clearing_account_id;
}
