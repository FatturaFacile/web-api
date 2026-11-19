<?php

declare(strict_types=1);

namespace Modules\Inventory\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'InventoryBarcodeScan',
    title: 'Barcode Scan Result',
    description: 'Decoded item, serial, or lot details from mobile scanner input',
    required: ['barcode', 'entity_type', 'is_found']
)]
class InventoryBarcodeScanSchema
{
    #[OA\Property(example: '8001234567890')]
    public string $barcode;

    #[OA\Property(example: 'stock_item', enum: ['stock_item', 'lot', 'serial_number', 'warehouse_bin'])]
    public string $entity_type;

    #[OA\Property(example: true)]
    public bool $is_found;

    #[OA\Property(type: 'object')]
    public array $data;
}
