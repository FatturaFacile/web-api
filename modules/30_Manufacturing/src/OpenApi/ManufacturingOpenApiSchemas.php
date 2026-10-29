<?php

declare(strict_types=1);

namespace Modules\Manufacturing\OpenApi;

final class ManufacturingOpenApiSchemas
{
    /**
     * @return array<string, mixed>
     */
    public static function schemas(): array
    {
        return [
            'ManufacturingItem' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'item_code' => ['type' => 'string', 'example' => 'ITM-PUMP-001'],
                    'name' => ['type' => 'string', 'example' => 'Hydraulic Pump Assembly'],
                    'item_type' => ['type' => 'string', 'enum' => ['raw_material', 'subassembly', 'finished_good', 'phantom']],
                    'standard_cost' => ['type' => 'number', 'format' => 'float', 'example' => 345.50],
                    'safety_stock' => ['type' => 'number', 'format' => 'float', 'example' => 10.0],
                    'lead_time_days' => ['type' => 'integer', 'example' => 5],
                ],
            ],
            'BillOfMaterials' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'bom_number' => ['type' => 'string', 'example' => 'BOM-A98B7C6D'],
                    'name' => ['type' => 'string', 'example' => 'Primary Pump BOM'],
                    'revision' => ['type' => 'string', 'example' => 'A'],
                    'version' => ['type' => 'integer', 'example' => 1],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'obsolete', 'archived']],
                    'base_quantity' => ['type' => 'number', 'example' => 1.0],
                ],
            ],
            'ProductionOrder' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'order_number' => ['type' => 'string', 'example' => 'MO-1A2B3C4D'],
                    'status' => ['type' => 'string', 'enum' => ['planned', 'released', 'scheduled', 'in_progress', 'completed', 'closed', 'cancelled']],
                    'target_quantity' => ['type' => 'number', 'example' => 50.0],
                    'completed_quantity' => ['type' => 'number', 'example' => 50.0],
                    'planned_start_date' => ['type' => 'string', 'format' => 'date'],
                    'planned_end_date' => ['type' => 'string', 'format' => 'date'],
                    'standard_cost_total' => ['type' => 'number', 'example' => 17275.00],
                    'actual_cost_total' => ['type' => 'number', 'example' => 16950.00],
                    'variance_total' => ['type' => 'number', 'example' => -325.00],
                ],
            ],
            'MrpRun' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'run_number' => ['type' => 'string', 'example' => 'MRP-9F8E7D6C'],
                    'run_type' => ['type' => 'string', 'enum' => ['regenerative', 'net_change']],
                    'status' => ['type' => 'string', 'example' => 'completed'],
                    'total_planned_orders' => ['type' => 'integer', 'example' => 12],
                    'total_shortages' => ['type' => 'integer', 'example' => 8],
                ],
            ],
        ];
    }
}
