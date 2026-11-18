<?php

declare(strict_types=1);

namespace Modules\Inventory\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'InventoryBook',
    title: 'Inventory Book',
    description: 'Libro inventari: rows per item / lot / warehouse with GL reconciliation',
    required: ['meta', 'rows', 'totals', 'reconciliation']
)]
class InventoryBookSchema
{
    #[OA\Property(type: 'object', properties: [
        new OA\Property(property: 'year', type: 'integer', example: 2026),
        new OA\Property(property: 'as_of', type: 'string', format: 'date'),
        new OA\Property(property: 'company_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'method_breakdown', type: 'object'),
        new OA\Property(property: 'generated_at', type: 'string', format: 'date-time'),
    ])]
    public array $meta;

    #[OA\Property(type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'sku', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'uom', type: 'string'),
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'lot_number', type: 'string', nullable: true),
        new OA\Property(property: 'quantity', type: 'string', example: '6.0000'),
        new OA\Property(property: 'unit_cost', type: 'string', example: '10.0000'),
        new OA\Property(property: 'total_value', type: 'string', example: '60.00'),
        new OA\Property(property: 'valuation_method', type: 'string', enum: ['fifo', 'weighted_average', 'standard_cost']),
    ]))]
    public array $rows;

    #[OA\Property(type: 'object', properties: [
        new OA\Property(property: 'quantity', type: 'string', example: '6.0000'),
        new OA\Property(property: 'total_value', type: 'string', example: '60.00'),
    ])]
    public array $totals;

    #[OA\Property(type: 'object', properties: [
        new OA\Property(property: 'gl_inventory_balance', type: 'string', example: '60.00'),
        new OA\Property(property: 'difference', type: 'string', example: '0.00'),
    ])]
    public array $reconciliation;
}
