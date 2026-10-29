<?php

declare(strict_types=1);

namespace Modules\AssetManagement\OpenApi;

final class AssetManagementOpenApiSchemas
{
    /**
     * OpenAPI 3.1 Schema Definitions for Swagger, Redoc, Scalar, and SDK Generation
     * (Angular, Flutter, PHP, C#, Java, TypeScript).
     *
     * @return array<string, mixed>
     */
    public static function schemas(): array
    {
        return [
            'EamFixedAsset' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'asset_number' => ['type' => 'string'],
                    'barcode_tag' => ['type' => 'string'],
                    'serial_number' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'category_id' => ['type' => 'string', 'format' => 'uuid'],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['draft', 'registered', 'capitalized', 'commissioned', 'in_service', 'active', 'under_maintenance', 'revalued', 'impaired', 'retired', 'disposed', 'sold'],
                    ],
                    'condition' => [
                        'type' => 'string',
                        'enum' => ['new', 'excellent', 'good', 'fair', 'poor', 'damaged', 'decommissioned'],
                    ],
                    'acquisition_cost' => ['type' => 'number', 'format' => 'float'],
                    'capitalized_cost' => ['type' => 'number', 'format' => 'float'],
                    'salvage_value' => ['type' => 'number', 'format' => 'float'],
                    'depreciable_base' => ['type' => 'number', 'format' => 'float'],
                    'accumulated_depreciation' => ['type' => 'number', 'format' => 'float'],
                    'carrying_amount' => ['type' => 'number', 'format' => 'float'],
                    'depreciation_method' => [
                        'type' => 'string',
                        'enum' => ['straight_line', 'declining_balance', 'double_declining_balance', 'units_of_production', 'custom'],
                    ],
                    'useful_life_years' => ['type' => 'integer'],
                    'is_fully_depreciated' => ['type' => 'boolean'],
                ],
                'required' => ['id', 'asset_number', 'name', 'category_id', 'status', 'carrying_amount'],
            ],
            'EamDepreciationRun' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'run_number' => ['type' => 'string'],
                    'period_code' => ['type' => 'string'],
                    'period_type' => ['type' => 'string', 'enum' => ['monthly', 'yearly']],
                    'ledger_type' => ['type' => 'string', 'enum' => ['statutory', 'ifrs', 'tax', 'management']],
                    'assets_processed_count' => ['type' => 'integer'],
                    'total_depreciation_amount' => ['type' => 'number', 'format' => 'float'],
                    'status' => ['type' => 'string', 'enum' => ['running', 'calculated', 'posted', 'reversed', 'failed']],
                ],
                'required' => ['id', 'run_number', 'period_code', 'period_type', 'status'],
            ],
            'EamMaintenanceWorkOrder' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'work_order_number' => ['type' => 'string'],
                    'asset_id' => ['type' => 'string', 'format' => 'uuid'],
                    'maintenance_type' => ['type' => 'string', 'enum' => ['preventive', 'corrective', 'condition_based', 'emergency', 'upgrade']],
                    'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
                    'status' => ['type' => 'string', 'enum' => ['scheduled', 'in_progress', 'pending_approval', 'completed', 'cancelled']],
                    'downtime_hours' => ['type' => 'number', 'format' => 'float'],
                    'total_cost' => ['type' => 'number', 'format' => 'float'],
                    'capitalize_as_upgrade' => ['type' => 'boolean'],
                ],
                'required' => ['id', 'work_order_number', 'asset_id', 'maintenance_type', 'status'],
            ],
        ];
    }
}
