<?php

declare(strict_types=1);

namespace Modules\AssetManagement\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response payloads of the module, derived from its API resources and models.
 */
#[OA\Schema(
    schema: 'AssetManagementAssetCategoryResource',
    description: 'JSON representation produced by AssetCategoryResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'asset_type'),
        new OA\Property(property: 'depreciation_method'),
        new OA\Property(property: 'useful_life_years', type: 'integer'),
        new OA\Property(property: 'statutory_rate_percent', type: 'number'),
        new OA\Property(property: 'salvage_value_percent', type: 'number'),
        new OA\Property(property: 'gl_asset_account_code'),
        new OA\Property(property: 'gl_accumulated_depreciation_code'),
        new OA\Property(property: 'gl_depreciation_expense_code'),
        new OA\Property(property: 'is_depreciable', type: 'boolean'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'translations'),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementAssetClassModel',
    description: 'Attributes of the Asset Class record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'default_maintenance_interval_days', type: 'integer', nullable: true),
        new OA\Property(property: 'requires_custodian', type: 'boolean', nullable: true),
        new OA\Property(property: 'requires_calibration', type: 'boolean', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
        new OA\Property(property: 'translations'),
        new OA\Property(property: 'technical_schema'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementAssetLocationResource',
    description: 'JSON representation produced by AssetLocationResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'parent_id', type: 'string'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'location_type'),
        new OA\Property(property: 'address'),
        new OA\Property(property: 'city'),
        new OA\Property(property: 'country', type: 'string'),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementFixedAssetResource',
    description: 'JSON representation produced by FixedAssetResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'asset_number'),
        new OA\Property(property: 'barcode_tag'),
        new OA\Property(property: 'serial_number'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'category_id', type: 'string'),
        new OA\Property(property: 'asset_class_id', type: 'string'),
        new OA\Property(property: 'location_id', type: 'string'),
        new OA\Property(property: 'parent_asset_id', type: 'string'),
        new OA\Property(property: 'custodian_user_id', type: 'string'),
        new OA\Property(property: 'project_id', type: 'string'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'condition'),
        new OA\Property(property: 'acquisition_type'),
        new OA\Property(property: 'acquisition_date', type: 'string'),
        new OA\Property(property: 'capitalization_date', type: 'string'),
        new OA\Property(property: 'in_service_date', type: 'string'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'acquisition_cost', type: 'number'),
        new OA\Property(property: 'capitalized_cost', type: 'number'),
        new OA\Property(property: 'salvage_value', type: 'number'),
        new OA\Property(property: 'depreciable_base', type: 'number'),
        new OA\Property(property: 'accumulated_depreciation', type: 'number'),
        new OA\Property(property: 'impairment_loss_total', type: 'number'),
        new OA\Property(property: 'revaluation_surplus_total', type: 'number'),
        new OA\Property(property: 'carrying_amount', type: 'number'),
        new OA\Property(property: 'depreciation_method'),
        new OA\Property(property: 'useful_life_years', type: 'integer'),
        new OA\Property(property: 'useful_life_months', type: 'integer'),
        new OA\Property(property: 'is_depreciable', type: 'boolean'),
        new OA\Property(property: 'is_fully_depreciated', type: 'boolean'),
        new OA\Property(property: 'approval_status'),
        new OA\Property(property: 'is_locked', type: 'boolean'),
        new OA\Property(property: 'specifications'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementAssetTransferResource',
    description: 'JSON representation produced by AssetTransferResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'transfer_number'),
        new OA\Property(property: 'asset_id', type: 'string'),
        new OA\Property(property: 'from_location_id', type: 'string'),
        new OA\Property(property: 'to_location_id', type: 'string'),
        new OA\Property(property: 'from_custodian_id', type: 'string'),
        new OA\Property(property: 'to_custodian_id', type: 'string'),
        new OA\Property(property: 'from_project_id', type: 'string'),
        new OA\Property(property: 'to_project_id', type: 'string'),
        new OA\Property(property: 'transfer_date', type: 'string'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'reason'),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementAssetValuationResource',
    description: 'JSON representation produced by AssetValuationResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'valuation_number'),
        new OA\Property(property: 'asset_id', type: 'string'),
        new OA\Property(property: 'valuation_type'),
        new OA\Property(property: 'valuation_date', type: 'string'),
        new OA\Property(property: 'carrying_amount_before', type: 'number'),
        new OA\Property(property: 'fair_value', type: 'number'),
        new OA\Property(property: 'value_in_use', type: 'number'),
        new OA\Property(property: 'recoverable_amount', type: 'number'),
        new OA\Property(property: 'adjustment_amount', type: 'number'),
        new OA\Property(property: 'revaluation_reserve_delta', type: 'number'),
        new OA\Property(property: 'impairment_loss_amount', type: 'number'),
        new OA\Property(property: 'carrying_amount_after', type: 'number'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'journal_entry_id', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementAssetDisposalResource',
    description: 'JSON representation produced by AssetDisposalResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'disposal_number'),
        new OA\Property(property: 'asset_id', type: 'string'),
        new OA\Property(property: 'disposal_type'),
        new OA\Property(property: 'disposal_date', type: 'string'),
        new OA\Property(property: 'asset_cost_at_disposal', type: 'number'),
        new OA\Property(property: 'accumulated_depreciation_at_disposal', type: 'number'),
        new OA\Property(property: 'carrying_amount_at_disposal', type: 'number'),
        new OA\Property(property: 'proceeds_amount', type: 'number'),
        new OA\Property(property: 'disposal_costs', type: 'number'),
        new OA\Property(property: 'gain_loss_amount', type: 'number'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'journal_entry_id', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementAssetComponentModel',
    description: 'Attributes of the Asset Component record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'cost_allocation', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'useful_life_months', type: 'integer', nullable: true),
        new OA\Property(property: 'installed_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'metadata'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementAssetCustodianModel',
    description: 'Attributes of the Asset Custodian record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'assigned_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'expected_return_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'returned_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementAssetAssignmentModel',
    description: 'Attributes of the Asset Assignment record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'assigned_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'expected_return_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'returned_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'metadata'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementProjectAssetAllocationModel',
    description: 'Attributes of the Project Asset Allocation record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'allocated_from', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'allocated_to', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'hourly_rate', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'daily_rate', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'utilized_hours', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'utilized_days', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'attributed_cost', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'released_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementDepreciationScheduleResource',
    description: 'JSON representation produced by DepreciationScheduleResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'asset_id', type: 'string'),
        new OA\Property(property: 'run_id', type: 'string'),
        new OA\Property(property: 'ledger_type'),
        new OA\Property(property: 'period_code'),
        new OA\Property(property: 'period_type'),
        new OA\Property(property: 'depreciation_method'),
        new OA\Property(property: 'opening_book_value', type: 'number'),
        new OA\Property(property: 'depreciation_amount', type: 'number'),
        new OA\Property(property: 'closing_book_value', type: 'number'),
        new OA\Property(property: 'accumulated_depreciation', type: 'number'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'journal_entry_id', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementDepreciationRunResource',
    description: 'JSON representation produced by DepreciationRunResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'run_number'),
        new OA\Property(property: 'period_code'),
        new OA\Property(property: 'period_type'),
        new OA\Property(property: 'ledger_type'),
        new OA\Property(property: 'assets_processed_count', type: 'integer'),
        new OA\Property(property: 'total_depreciation_amount', type: 'number'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'executed_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementMaintenancePlanModel',
    description: 'Attributes of the Maintenance Plan record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'frequency_days', type: 'integer', nullable: true),
        new OA\Property(property: 'meter_interval_units', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'estimated_duration_hours', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'estimated_cost', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'last_executed_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'next_due_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
        new OA\Property(property: 'checklist'),
        new OA\Property(property: 'translations'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'AssetManagementMaintenanceWorkOrderResource',
    description: 'JSON representation produced by MaintenanceWorkOrderResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'work_order_number'),
        new OA\Property(property: 'asset_id', type: 'string'),
        new OA\Property(property: 'plan_id', type: 'string'),
        new OA\Property(property: 'maintenance_type'),
        new OA\Property(property: 'priority'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'scheduled_date', type: 'string'),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'downtime_hours', type: 'number'),
        new OA\Property(property: 'labor_cost', type: 'number'),
        new OA\Property(property: 'parts_cost', type: 'number'),
        new OA\Property(property: 'external_service_cost', type: 'number'),
        new OA\Property(property: 'total_cost', type: 'number'),
        new OA\Property(property: 'capitalize_as_upgrade', type: 'boolean'),
        new OA\Property(property: 'is_locked', type: 'boolean'),
    ]
)]
final class AssetManagementResponseSchemas {}
