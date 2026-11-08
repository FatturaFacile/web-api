<?php

declare(strict_types=1);

namespace Modules\AssetManagement\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request bodies of the asset management endpoints, mirroring their form requests. Amounts are decimal strings.
 */
#[OA\Schema(
    schema: 'AssetManagementStoreFixedAssetRequest',
    description: 'Validation of a new fixed asset.',
    required: ['name', 'category_id', 'acquisition_cost'],
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'category_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'asset_class_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'location_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'parent_asset_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'custodian_user_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'project_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'asset_number', type: 'string', nullable: true),
        new OA\Property(property: 'acquisition_cost', type: 'string', format: 'decimal', example: '1000.00'),
        new OA\Property(property: 'salvage_value', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'replacement_cost', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'acquisition_type', type: 'string', enum: ['purchase', 'inventory_conversion', 'cip_project', 'lease', 'donated', 'manual'], nullable: true),
        new OA\Property(property: 'acquisition_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'depreciation_method', type: 'string', enum: ['straight_line', 'declining_balance', 'double_declining_balance', 'units_of_production'], nullable: true),
        new OA\Property(property: 'pro_rata_convention', type: 'string', enum: ['full', 'monthly', 'daily', 'half_year'], nullable: true),
        new OA\Property(property: 'useful_life_years', type: 'integer', nullable: true),
        new OA\Property(property: 'total_expected_units', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'currency', type: 'string', nullable: true),
        new OA\Property(property: 'serial_number', type: 'string', nullable: true),
        new OA\Property(property: 'barcode_tag', type: 'string', nullable: true),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'condition', type: 'string', enum: ['new', 'excellent', 'good', 'fair', 'poor', 'damaged'], nullable: true),
        new OA\Property(property: 'warranty_expiry_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'warranty_provider', type: 'string', nullable: true),
        new OA\Property(property: 'insurance_provider', type: 'string', nullable: true),
        new OA\Property(property: 'insurance_policy_number', type: 'string', nullable: true),
        new OA\Property(property: 'insured_value', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'insurance_expiry_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'specifications', type: 'array', items: new OA\Items, nullable: true),
        new OA\Property(property: 'metadata', type: 'array', items: new OA\Items, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementUpdateFixedAssetRequest',
    description: 'Validation of the descriptive, warranty and insurance data of an asset; values change only through the lifecycle operations.',
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'serial_number', type: 'string', nullable: true),
        new OA\Property(property: 'barcode_tag', type: 'string', nullable: true),
        new OA\Property(property: 'condition', type: 'string', enum: ['new', 'excellent', 'good', 'fair', 'poor', 'damaged']),
        new OA\Property(property: 'asset_class_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'replacement_cost', type: 'string', format: 'decimal', example: '1000.00'),
        new OA\Property(property: 'warranty_expiry_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'warranty_provider', type: 'string', nullable: true),
        new OA\Property(property: 'insurance_provider', type: 'string', nullable: true),
        new OA\Property(property: 'insurance_policy_number', type: 'string', nullable: true),
        new OA\Property(property: 'insured_value', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'insurance_expiry_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'specifications', type: 'array', items: new OA\Items, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementAcquireAssetRequest',
    description: 'Validation of an asset registered from a passive invoice line or a purchase order item.',
    required: ['source_type', 'source_id', 'category_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'source_type', type: 'string', enum: ['passive_invoice_line', 'purchase_order_item']),
        new OA\Property(property: 'source_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'category_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string', nullable: true),
        new OA\Property(property: 'asset_class_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'location_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'custodian_user_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'serial_number', type: 'string', nullable: true),
        new OA\Property(property: 'salvage_value', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'useful_life_years', type: 'integer', nullable: true),
        new OA\Property(property: 'warranty_expiry_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'capitalize', type: 'boolean', nullable: true),
        new OA\Property(property: 'capitalization_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'in_service_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'auto_post_to_gl', type: 'boolean', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementCapitalizeAssetRequest',
    description: 'Validation of the capitalization of an asset.',
    type: 'object',
    properties: [
        new OA\Property(property: 'capitalized_cost', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'salvage_value', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'capitalization_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'in_service_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'depreciation_start_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'depreciation_method', type: 'string', enum: ['straight_line', 'declining_balance', 'double_declining_balance', 'units_of_production'], nullable: true),
        new OA\Property(property: 'pro_rata_convention', type: 'string', enum: ['full', 'monthly', 'daily', 'half_year'], nullable: true),
        new OA\Property(property: 'useful_life_years', type: 'integer', nullable: true),
        new OA\Property(property: 'auto_post_to_gl', type: 'boolean', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementCommissionAssetRequest',
    description: 'Validation of the commissioning of an asset.',
    type: 'object',
    properties: [
        new OA\Property(property: 'commissioning_date', type: 'string', format: 'date', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementTransferAssetRequest',
    description: 'Validation of an asset transfer; at least one destination is required.',
    type: 'object',
    properties: [
        new OA\Property(property: 'to_location_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'to_custodian_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'to_project_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'to_cost_center_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'transfer_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'reason', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementValuateAssetRequest',
    description: 'Validation of a revaluation or impairment test.',
    required: ['valuation_type', 'fair_value'],
    type: 'object',
    properties: [
        new OA\Property(property: 'valuation_type', type: 'string', enum: ['revaluation', 'impairment']),
        new OA\Property(property: 'fair_value', type: 'string', format: 'decimal', example: '1000.00'),
        new OA\Property(property: 'value_in_use', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'valuation_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'appraiser_name', type: 'string', nullable: true),
        new OA\Property(property: 'reason', type: 'string', nullable: true),
        new OA\Property(property: 'auto_post_to_gl', type: 'boolean', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementReverseImpairmentRequest',
    description: 'Validation of an impairment reversal.',
    required: ['recoverable_amount'],
    type: 'object',
    properties: [
        new OA\Property(property: 'recoverable_amount', type: 'string', format: 'decimal', example: '1000.00'),
        new OA\Property(property: 'reason', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementDisposeAssetRequest',
    description: 'Validation of a total or partial disposal (sale, scrapping, retirement, donation, loss).',
    required: ['disposal_type'],
    type: 'object',
    properties: [
        new OA\Property(property: 'disposal_type', type: 'string', enum: ['sale', 'scrap', 'retirement', 'donation', 'theft_loss']),
        new OA\Property(property: 'disposed_percentage', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'proceeds_amount', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'disposal_costs', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'disposal_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'buyer_contact_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'reason', type: 'string', nullable: true),
        new OA\Property(property: 'auto_post_to_gl', type: 'boolean', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementAttachComponentRequest',
    description: 'Validation of a new component of an asset.',
    required: ['name'],
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'component_code', type: 'string', nullable: true),
        new OA\Property(property: 'component_asset_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'serial_number', type: 'string', nullable: true),
        new OA\Property(property: 'cost_allocation', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'useful_life_months', type: 'integer', nullable: true),
        new OA\Property(property: 'condition', type: 'string', enum: ['new', 'excellent', 'good', 'fair', 'poor', 'damaged'], nullable: true),
        new OA\Property(property: 'installed_date', type: 'string', format: 'date', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementRetireComponentRequest',
    description: 'Validation of the retirement of a component.',
    type: 'object',
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['replaced', 'detached', 'failed'], nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementAssignCustodianRequest',
    description: 'Validation of a new custodian of an asset.',
    required: ['custodian_user_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'custodian_user_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'department', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementAssignAssetRequest',
    description: 'Validation of an assignment of an asset.',
    required: ['assignee_type', 'assignee_name'],
    type: 'object',
    properties: [
        new OA\Property(property: 'assignee_type', type: 'string', enum: ['employee', 'department', 'project', 'site', 'vehicle_operator']),
        new OA\Property(property: 'assignee_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'assignee_name', type: 'string'),
        new OA\Property(property: 'department_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'project_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'location_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'assigned_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'expected_return_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementAllocateProjectAssetRequest',
    description: 'Validation of an allocation of an asset to a project.',
    required: ['project_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'project_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'allocated_from', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'allocated_to', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'hourly_rate', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'daily_rate', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'utilized_hours', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'utilized_days', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementStoreAssetCategoryRequest',
    description: 'Validation of an asset category, its depreciation presets, fiscal parameters (configured by the tenant, none shipped) and ledger accounts.',
    type: 'object',
    properties: [
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'parent_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'asset_type', type: 'string', enum: ['tangible', 'intangible', 'financial', 'real_estate', 'right_of_use'], nullable: true),
        new OA\Property(property: 'depreciation_method', type: 'string', enum: ['straight_line', 'declining_balance', 'double_declining_balance', 'units_of_production'], nullable: true),
        new OA\Property(property: 'useful_life_years', type: 'integer', nullable: true),
        new OA\Property(property: 'statutory_rate_percent', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'salvage_value_percent', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'pro_rata_convention', type: 'string', enum: ['full', 'monthly', 'daily', 'half_year'], nullable: true),
        new OA\Property(property: 'declining_balance_factor', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'fiscal_depreciation_method', type: 'string', enum: ['straight_line', 'declining_balance', 'double_declining_balance', 'units_of_production'], nullable: true),
        new OA\Property(property: 'fiscal_rate_percent', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'fiscal_useful_life_years', type: 'integer', nullable: true),
        new OA\Property(property: 'fiscal_pro_rata_convention', type: 'string', enum: ['full', 'monthly', 'daily', 'half_year'], nullable: true),
        new OA\Property(property: 'rate_reference', type: 'string', nullable: true),
        new OA\Property(property: 'gl_asset_account_code', type: 'string', nullable: true),
        new OA\Property(property: 'gl_accumulated_depreciation_code', type: 'string', nullable: true),
        new OA\Property(property: 'gl_depreciation_expense_code', type: 'string', nullable: true),
        new OA\Property(property: 'gl_impairment_account_code', type: 'string', nullable: true),
        new OA\Property(property: 'gl_revaluation_reserve_code', type: 'string', nullable: true),
        new OA\Property(property: 'gl_disposal_gain_loss_code', type: 'string', nullable: true),
        new OA\Property(property: 'gl_disposal_loss_code', type: 'string', nullable: true),
        new OA\Property(property: 'gl_disposal_proceeds_code', type: 'string', nullable: true),
        new OA\Property(property: 'gl_clearing_account_code', type: 'string', nullable: true),
        new OA\Property(property: 'is_depreciable', type: 'boolean', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementStoreAssetClassRequest',
    description: 'Validation of an operational asset class.',
    required: ['code', 'name'],
    type: 'object',
    properties: [
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'criticality', type: 'string', enum: ['low', 'medium', 'high', 'mission_critical'], nullable: true),
        new OA\Property(property: 'default_maintenance_interval_days', type: 'integer', nullable: true),
        new OA\Property(property: 'requires_custodian', type: 'boolean', nullable: true),
        new OA\Property(property: 'requires_calibration', type: 'boolean', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementStoreAssetLocationRequest',
    description: 'Validation of an asset location.',
    type: 'object',
    properties: [
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'parent_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'location_type', type: 'string', enum: ['facility', 'building', 'floor', 'room', 'yard', 'mobile', 'project_site'], nullable: true),
        new OA\Property(property: 'address', type: 'string', nullable: true),
        new OA\Property(property: 'city', type: 'string', nullable: true),
        new OA\Property(property: 'country', type: 'string', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementRunDepreciationRequest',
    description: 'Validation of a depreciation run or simulation.',
    required: ['period_code'],
    type: 'object',
    properties: [
        new OA\Property(property: 'period_code', type: 'string'),
        new OA\Property(property: 'period_type', type: 'string', enum: ['monthly', 'quarterly', 'yearly'], nullable: true),
        new OA\Property(property: 'ledger_type', type: 'string', enum: ['statutory', 'tax', 'ifrs', 'management', 'civil', 'fiscal'], nullable: true),
        new OA\Property(property: 'single_asset_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'category_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'units_produced_by_asset_id', type: 'array', items: new OA\Items, nullable: true),
        new OA\Property(property: 'auto_post_to_gl', type: 'boolean', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementAssetTagsRequest',
    description: 'Validation of a bulk request of asset tag data.',
    required: ['asset_ids'],
    type: 'object',
    properties: [
        new OA\Property(property: 'asset_ids', type: 'array', items: new OA\Items),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementStoreMeterReadingRequest',
    description: 'Validation of a meter reading.',
    required: ['meter_code', 'reading_value'],
    type: 'object',
    properties: [
        new OA\Property(property: 'meter_code', type: 'string'),
        new OA\Property(property: 'reading_value', type: 'string', format: 'decimal', example: '1000.00'),
        new OA\Property(property: 'read_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementStoreMaintenancePlanRequest',
    description: 'Validation of a preventive maintenance plan (time, meter or both).',
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'plan_code', type: 'string', nullable: true),
        new OA\Property(property: 'asset_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'asset_class_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'maintenance_type', type: 'string', enum: ['preventive', 'condition_based', 'predictive', 'calibration'], nullable: true),
        new OA\Property(property: 'trigger_type', type: 'string', enum: ['time', 'meter', 'time_or_meter'], nullable: true),
        new OA\Property(property: 'frequency_days', type: 'integer', nullable: true),
        new OA\Property(property: 'meter_code', type: 'string', nullable: true),
        new OA\Property(property: 'meter_interval_units', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'lead_time_days', type: 'integer', nullable: true),
        new OA\Property(property: 'estimated_duration_hours', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'estimated_cost', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'next_due_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
        new OA\Property(property: 'checklist', type: 'array', items: new OA\Items, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementGenerateWorkOrderRequest',
    description: 'Validation of a work order opened from a plan.',
    type: 'object',
    properties: [
        new OA\Property(property: 'scheduled_date', type: 'string', format: 'date', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementStoreMaintenanceWorkOrderRequest',
    description: 'Validation of a new maintenance work order.',
    required: ['asset_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'asset_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'plan_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'maintenance_type', type: 'string', enum: ['preventive', 'corrective', 'condition_based', 'emergency', 'upgrade'], nullable: true),
        new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high', 'critical'], nullable: true),
        new OA\Property(property: 'scheduled_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'assigned_technician_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'problem_description', type: 'string', nullable: true),
        new OA\Property(property: 'estimated_cost', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementStartWorkOrderRequest',
    description: 'Validation of the start of a work order.',
    type: 'object',
    properties: [
        new OA\Property(property: 'stops_asset', type: 'boolean', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementConsumePartRequest',
    description: 'Validation of a spare part issued to a work order.',
    required: ['product_id', 'quantity'],
    type: 'object',
    properties: [
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'quantity', type: 'string', format: 'decimal', example: '1000.00'),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementCompleteMaintenanceWorkOrderRequest',
    description: 'Validation of the completion of a work order.',
    type: 'object',
    properties: [
        new OA\Property(property: 'downtime_hours', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'labor_cost', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'parts_cost', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'external_service_cost', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'capitalize_as_upgrade', type: 'boolean', nullable: true),
        new OA\Property(property: 'condition_after_maintenance', type: 'string', enum: ['new', 'excellent', 'good', 'fair', 'poor', 'damaged'], nullable: true),
        new OA\Property(property: 'meter_reading', type: 'string', format: 'decimal', example: '1000.00', nullable: true),
        new OA\Property(property: 'resolution_notes', type: 'string', nullable: true),
        new OA\Property(property: 'parts_consumed', type: 'array', items: new OA\Items, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementCancelWorkOrderRequest',
    description: 'Validation of the cancellation of a work order.',
    type: 'object',
    properties: [
        new OA\Property(property: 'reason', type: 'string', nullable: true),
    ]
)]
final class AssetManagementRequestSchemas {}
