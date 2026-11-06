<?php

declare(strict_types=1);

namespace Modules\AssetManagement\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request payloads of the module, derived from its form requests.
 */
#[OA\Schema(
    schema: 'AssetManagementStoreFixedAssetRequest',
    description: 'Request payload validated by StoreFixedAssetRequest',
    required: ['name', 'category_id', 'acquisition_cost'],
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 255),
        new OA\Property(property: 'category_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'asset_class_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'location_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'parent_asset_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'custodian_user_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'project_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'acquisition_cost', type: 'number', minimum: 0),
        new OA\Property(property: 'salvage_value', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'acquisition_type', type: 'string', nullable: true, enum: ['purchase', 'inventory_conversion', 'cip_project', 'lease', 'donated', 'manual']),
        new OA\Property(property: 'acquisition_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'depreciation_method', type: 'string', nullable: true, enum: ['straight_line', 'declining_balance', 'double_declining_balance', 'units_of_production', 'custom']),
        new OA\Property(property: 'useful_life_years', type: 'integer', minimum: 1, maximum: 100, nullable: true),
        new OA\Property(property: 'total_expected_units', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'serial_number', type: 'string', maxLength: 100, nullable: true),
        new OA\Property(property: 'barcode_tag', type: 'string', maxLength: 100, nullable: true),
        new OA\Property(property: 'condition', type: 'string', nullable: true, enum: ['new', 'excellent', 'good', 'fair', 'poor', 'damaged', 'decommissioned']),
        new OA\Property(property: 'specifications', type: 'array', nullable: true, items: new OA\Items),
        new OA\Property(property: 'metadata', type: 'array', nullable: true, items: new OA\Items),
        new OA\Property(property: 'description'),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementCapitalizeAssetRequest',
    description: 'Request payload validated by CapitalizeAssetRequest',
    required: ['capitalized_cost'],
    type: 'object',
    properties: [
        new OA\Property(property: 'capitalized_cost', type: 'number', minimum: 0.01),
        new OA\Property(property: 'salvage_value', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'capitalization_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'in_service_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'depreciation_method', type: 'string', nullable: true, enum: ['straight_line', 'declining_balance', 'double_declining_balance', 'units_of_production', 'custom']),
        new OA\Property(property: 'useful_life_years', type: 'integer', minimum: 1, maximum: 100, nullable: true),
        new OA\Property(property: 'auto_post_to_gl', type: 'boolean', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementTransferAssetRequest',
    description: 'Request payload validated by TransferAssetRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'to_location_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'to_custodian_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'to_project_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'transfer_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'reason', type: 'string', maxLength: 1000, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementValuateAssetRequest',
    description: 'Request payload validated by ValuateAssetRequest',
    required: ['valuation_type', 'fair_value'],
    type: 'object',
    properties: [
        new OA\Property(property: 'valuation_type', type: 'string', enum: ['revaluation', 'impairment']),
        new OA\Property(property: 'fair_value', type: 'number', minimum: 0),
        new OA\Property(property: 'value_in_use', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'valuation_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'appraiser_name', type: 'string', maxLength: 150, nullable: true),
        new OA\Property(property: 'reason', type: 'string', maxLength: 1000, nullable: true),
        new OA\Property(property: 'auto_post_to_gl', type: 'boolean', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementDisposeAssetRequest',
    description: 'Request payload validated by DisposeAssetRequest',
    required: ['disposal_type'],
    type: 'object',
    properties: [
        new OA\Property(property: 'disposal_type', type: 'string', enum: ['sale', 'scrap', 'retirement', 'donation', 'theft_loss']),
        new OA\Property(property: 'proceeds_amount', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'disposal_costs', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'disposal_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'buyer_contact_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'reason', type: 'string', maxLength: 1000, nullable: true),
        new OA\Property(property: 'auto_post_to_gl', type: 'boolean', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementRunDepreciationRequest',
    description: 'Request payload validated by RunDepreciationRequest',
    required: ['period_code'],
    type: 'object',
    properties: [
        new OA\Property(property: 'period_code', type: 'string', maxLength: 20),
        new OA\Property(property: 'period_type', type: 'string', nullable: true, enum: ['monthly', 'yearly']),
        new OA\Property(property: 'ledger_type', type: 'string', nullable: true, enum: ['statutory', 'ifrs', 'tax', 'management']),
        new OA\Property(property: 'single_asset_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'units_produced_by_asset_id', type: 'array', nullable: true, items: new OA\Items),
        new OA\Property(property: 'auto_post_to_gl', type: 'boolean', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementStoreMaintenanceWorkOrderRequest',
    description: 'Request payload validated by StoreMaintenanceWorkOrderRequest',
    required: ['asset_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'asset_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'maintenance_type', type: 'string', nullable: true, enum: ['preventive', 'corrective', 'condition_based', 'emergency', 'upgrade']),
        new OA\Property(property: 'priority', type: 'string', nullable: true, enum: ['low', 'medium', 'high', 'critical']),
        new OA\Property(property: 'scheduled_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'plan_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'assigned_technician_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'problem_description', type: 'string', maxLength: 2000, nullable: true),
        new OA\Property(property: 'estimated_cost', type: 'number', minimum: 0, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementCompleteMaintenanceWorkOrderRequest',
    description: 'Request payload validated by CompleteMaintenanceWorkOrderRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'downtime_hours', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'labor_cost', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'parts_cost', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'external_service_cost', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'capitalize_as_upgrade', type: 'boolean', nullable: true),
        new OA\Property(property: 'condition_after_maintenance', type: 'string', nullable: true, enum: ['new', 'excellent', 'good', 'fair', 'poor', 'damaged']),
        new OA\Property(property: 'resolution_notes', type: 'string', maxLength: 2000, nullable: true),
        new OA\Property(property: 'parts_consumed', type: 'array', nullable: true, items: new OA\Items),
    ]
)]
final class AssetManagementRequestSchemas {}
