<?php

declare(strict_types=1);

namespace Modules\Inventory\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'InventoryCostLayer',
    title: 'Inventory Cost Layer',
    description: 'Receipt cost layer; FIFO depletes remaining_quantity, landed costs uplift landed_cost_unit',
    required: ['id', 'item_id', 'received_at', 'original_quantity', 'remaining_quantity', 'unit_cost', 'landed_cost_unit', 'effective_unit_cost']
)]
class InventoryCostLayerSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(format: 'uuid')]
    public string $item_id;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $lot_id;

    #[OA\Property(format: 'date-time')]
    public string $received_at;

    #[OA\Property(example: '10.0000')]
    public string $original_quantity;

    #[OA\Property(example: '6.0000')]
    public string $remaining_quantity;

    #[OA\Property(example: '5.0000')]
    public string $unit_cost;

    #[OA\Property(example: '5.0000')]
    public string $landed_cost_unit;

    #[OA\Property(example: '10.0000')]
    public string $effective_unit_cost;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $receipt_id;
}
