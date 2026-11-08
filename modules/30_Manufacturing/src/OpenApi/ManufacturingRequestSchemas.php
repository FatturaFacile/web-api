<?php

declare(strict_types=1);

namespace Modules\Manufacturing\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request bodies of the manufacturing endpoints, mirroring their form requests. Quantities, minutes and amounts are
 * decimal strings (or numbers).
 */
#[OA\Schema(schema: 'MfgStoreItemRequest', required: ['item_code', 'name', 'item_type'], type: 'object', properties: [
    new OA\Property(property: 'item_code', type: 'string', maxLength: 50),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'item_type', type: 'string', enum: ['raw_material', 'subassembly', 'finished_good', 'phantom']),
    new OA\Property(property: 'procurement_type', type: 'string', enum: ['make', 'buy', 'subcontract'], nullable: true),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'default_warehouse_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'uom', type: 'string', nullable: true),
    new OA\Property(property: 'standard_cost', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'safety_stock', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'min_lot_size', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'max_lot_size', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'lot_size_multiple', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'lead_time_days', type: 'integer', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
])]
#[OA\Schema(schema: 'MfgUpdateItemRequest', description: 'Fields of MfgStoreItemRequest except item_code, all optional.', type: 'object', properties: [
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'procurement_type', type: 'string', enum: ['make', 'buy', 'subcontract'], nullable: true),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'standard_cost', type: 'string', format: 'decimal'),
    new OA\Property(property: 'safety_stock', type: 'string', format: 'decimal'),
    new OA\Property(property: 'min_lot_size', type: 'string', format: 'decimal'),
    new OA\Property(property: 'lead_time_days', type: 'integer'),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'MfgStoreWorkCenterRequest', required: ['work_center_code', 'name'], type: 'object', properties: [
    new OA\Property(property: 'work_center_code', type: 'string', maxLength: 50),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'department_code', type: 'string', nullable: true),
    new OA\Property(property: 'capacity_hours_per_day', type: 'string', format: 'decimal'),
    new OA\Property(property: 'efficiency_rate', type: 'string', format: 'decimal'),
    new OA\Property(property: 'availability_rate', type: 'string', format: 'decimal'),
    new OA\Property(property: 'hourly_cost_rate', type: 'string', format: 'decimal'),
    new OA\Property(property: 'hourly_overhead_rate', type: 'string', format: 'decimal'),
    new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive']),
])]
#[OA\Schema(schema: 'MfgSetCalendarRequest', required: ['days'], type: 'object', properties: [
    new OA\Property(property: 'days', type: 'array', items: new OA\Items(required: ['weekday', 'available_hours'], properties: [new OA\Property(property: 'weekday', type: 'integer', maximum: 7, minimum: 1), new OA\Property(property: 'available_hours', type: 'string', format: 'decimal')], type: 'object')),
])]
#[OA\Schema(schema: 'MfgCalendarExceptionRequest', required: ['calendar_date', 'available_hours'], type: 'object', properties: [
    new OA\Property(property: 'calendar_date', type: 'string', format: 'date'),
    new OA\Property(property: 'available_hours', type: 'string', format: 'decimal'),
    new OA\Property(property: 'reason', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'MfgCreateBomRequest', required: ['mfg_item_id', 'name', 'components'], type: 'object', properties: [
    new OA\Property(property: 'mfg_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'bom_number', type: 'string', nullable: true),
    new OA\Property(property: 'revision', type: 'string', nullable: true),
    new OA\Property(property: 'bom_type', type: 'string', enum: ['standard', 'phantom', 'engineering'], nullable: true),
    new OA\Property(property: 'base_quantity', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'effective_from', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'effective_to', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'components', type: 'array', items: new OA\Items(required: ['component_item_id', 'quantity_per'], properties: [
        new OA\Property(property: 'component_item_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'quantity_per', type: 'string', format: 'decimal'),
        new OA\Property(property: 'sequence', type: 'integer', nullable: true),
        new OA\Property(property: 'scrap_percentage', type: 'string', format: 'decimal', nullable: true),
        new OA\Property(property: 'yield_percentage', type: 'string', format: 'decimal', nullable: true),
        new OA\Property(property: 'is_phantom', type: 'boolean', nullable: true),
        new OA\Property(property: 'issue_method', type: 'string', enum: ['manual', 'backflush'], nullable: true),
        new OA\Property(property: 'operation_sequence', type: 'integer', nullable: true),
    ], type: 'object')),
])]
#[OA\Schema(schema: 'MfgUpdateBomRequest', description: 'Changes to a draft revision; components replace the existing ones.', type: 'object', properties: [
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'base_quantity', type: 'string', format: 'decimal'),
    new OA\Property(property: 'effective_from', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'effective_to', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'components', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'MfgComponentChange', required: ['action', 'component_item_id'], type: 'object', properties: [
    new OA\Property(property: 'action', type: 'string', enum: ['add', 'update', 'remove']),
    new OA\Property(property: 'component_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'quantity_per', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'scrap_percentage', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'yield_percentage', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'issue_method', type: 'string', enum: ['manual', 'backflush'], nullable: true),
    new OA\Property(property: 'operation_sequence', type: 'integer', nullable: true),
])]
#[OA\Schema(schema: 'MfgReviseBomRequest', required: ['reason'], type: 'object', properties: [
    new OA\Property(property: 'new_revision', type: 'string', nullable: true),
    new OA\Property(property: 'reason', type: 'string'),
    new OA\Property(property: 'effective_from', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'components', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgComponentChange'), nullable: true),
])]
#[OA\Schema(schema: 'MfgCreateRoutingRequest', required: ['mfg_item_id', 'name', 'operations'], type: 'object', properties: [
    new OA\Property(property: 'mfg_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'revision', type: 'string', nullable: true),
    new OA\Property(property: 'is_default', type: 'boolean', nullable: true),
    new OA\Property(property: 'effective_from', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'effective_to', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'operations', type: 'array', items: new OA\Items(required: ['operation_code', 'name', 'work_center_id'], properties: [
        new OA\Property(property: 'sequence', type: 'integer', nullable: true),
        new OA\Property(property: 'operation_code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'work_center_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'machine_center_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'setup_time_minutes', type: 'string', format: 'decimal', nullable: true),
        new OA\Property(property: 'run_time_minutes_per_unit', type: 'string', format: 'decimal', nullable: true),
        new OA\Property(property: 'labor_time_minutes_per_unit', type: 'string', format: 'decimal', nullable: true),
        new OA\Property(property: 'is_subcontracted', type: 'boolean', nullable: true),
        new OA\Property(property: 'subcontractor_contact_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'subcontract_unit_cost', type: 'string', format: 'decimal', nullable: true),
        new OA\Property(property: 'quality_plan_id', type: 'string', format: 'uuid', nullable: true),
    ], type: 'object')),
])]
#[OA\Schema(schema: 'MfgCreateEcoRequest', required: ['bom_id', 'title', 'reason', 'components'], type: 'object', properties: [
    new OA\Property(property: 'bom_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'reason', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'change_type', type: 'string', enum: ['bom_change', 'component_substitution', 'quantity_change'], nullable: true),
    new OA\Property(property: 'effective_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'new_revision', type: 'string', nullable: true),
    new OA\Property(property: 'components', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgComponentChange')),
])]
#[OA\Schema(schema: 'MfgReasonRequest', required: ['reason'], type: 'object', properties: [
    new OA\Property(property: 'reason', type: 'string'),
])]
#[OA\Schema(schema: 'MfgPostingDateRequest', type: 'object', properties: [
    new OA\Property(property: 'posting_date', description: 'Document date of the posting, default today; it must fall in an open fiscal period', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'MfgCreateProductionOrderRequest', required: ['mfg_item_id', 'target_quantity', 'planned_start_date', 'planned_end_date'], type: 'object', properties: [
    new OA\Property(property: 'mfg_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'target_quantity', type: 'string', format: 'decimal'),
    new OA\Property(property: 'planned_start_date', type: 'string', format: 'date'),
    new OA\Property(property: 'planned_end_date', type: 'string', format: 'date'),
    new OA\Property(property: 'bom_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'routing_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'project_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'sales_order_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'receipt_warehouse_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'MfgRescheduleRequest', required: ['planned_start_date', 'planned_end_date'], type: 'object', properties: [
    new OA\Property(property: 'planned_start_date', type: 'string', format: 'date'),
    new OA\Property(property: 'planned_end_date', type: 'string', format: 'date'),
])]
#[OA\Schema(schema: 'MfgIssueMaterialsRequest', required: ['lines'], type: 'object', properties: [
    new OA\Property(property: 'posting_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'allow_over_issue', type: 'boolean', nullable: true),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(required: ['line_id', 'quantity'], properties: [new OA\Property(property: 'line_id', type: 'string', format: 'uuid'), new OA\Property(property: 'quantity', type: 'string', format: 'decimal'), new OA\Property(property: 'lot_id', type: 'string', format: 'uuid', nullable: true)], type: 'object')),
])]
#[OA\Schema(schema: 'MfgConfirmOperationRequest', type: 'object', properties: [
    new OA\Property(property: 'setup_minutes', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'run_minutes', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'labor_minutes', description: 'Default: setup plus run minutes', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'downtime_minutes', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'good_quantity', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'scrap_quantity', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'scrap_reason', type: 'string', nullable: true),
    new OA\Property(property: 'is_final', type: 'boolean', nullable: true),
    new OA\Property(property: 'posting_date', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'MfgReceiveOutputRequest', required: ['quantity'], type: 'object', properties: [
    new OA\Property(property: 'quantity', type: 'string', format: 'decimal'),
    new OA\Property(property: 'lot_number', description: 'Required for lot-tracked items', type: 'string', nullable: true),
    new OA\Property(property: 'serial_numbers', description: 'One per unit for serialized items', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'posting_date', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'MfgSendSubcontractRequest', required: ['production_order_operation_id', 'subcontractor_contact_id', 'subcontract_warehouse_id', 'quantity'], type: 'object', properties: [
    new OA\Property(property: 'production_order_operation_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'subcontractor_contact_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'subcontract_warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'source_warehouse_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'quantity', type: 'string', format: 'decimal'),
    new OA\Property(property: 'unit_cost', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'posting_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'components', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'line_id', type: 'string', format: 'uuid'), new OA\Property(property: 'quantity', type: 'string', format: 'decimal')], type: 'object'), nullable: true),
])]
#[OA\Schema(schema: 'MfgReceiveSubcontractRequest', required: ['good_quantity'], type: 'object', properties: [
    new OA\Property(property: 'good_quantity', type: 'string', format: 'decimal'),
    new OA\Property(property: 'scrap_quantity', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'posting_date', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'MfgStartExecutionRequest', required: ['production_order_operation_id'], type: 'object', properties: [
    new OA\Property(property: 'production_order_operation_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'operator_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'machine_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'MfgCompleteExecutionRequest', required: ['good_units'], type: 'object', properties: [
    new OA\Property(property: 'good_units', type: 'string', format: 'decimal'),
    new OA\Property(property: 'scrap_units', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'scrap_reason', type: 'string', nullable: true),
    new OA\Property(property: 'setup_minutes', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'labor_minutes', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'is_final', type: 'boolean', nullable: true),
    new OA\Property(property: 'posting_date', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'MfgRecordDowntimeRequest', required: ['downtime_minutes', 'reason'], type: 'object', properties: [
    new OA\Property(property: 'downtime_minutes', type: 'string', format: 'decimal'),
    new OA\Property(property: 'reason', type: 'string'),
])]
#[OA\Schema(schema: 'MfgRunMrpRequest', required: ['horizon_start_date', 'horizon_end_date'], type: 'object', properties: [
    new OA\Property(property: 'horizon_start_date', type: 'string', format: 'date'),
    new OA\Property(property: 'horizon_end_date', description: 'At most two years after the start', type: 'string', format: 'date'),
    new OA\Property(property: 'run_type', type: 'string', enum: ['regenerative', 'net_change'], nullable: true),
    new OA\Property(property: 'item_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), nullable: true),
])]
#[OA\Schema(schema: 'MfgStoreForecastRequest', required: ['mfg_item_id', 'forecast_date', 'quantity'], type: 'object', properties: [
    new OA\Property(property: 'mfg_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'forecast_date', type: 'string', format: 'date'),
    new OA\Property(property: 'quantity', type: 'string', format: 'decimal'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'MfgProcurementLinkRequest', required: ['procurement_document_type', 'procurement_document_id'], type: 'object', properties: [
    new OA\Property(property: 'procurement_document_type', type: 'string', enum: ['purchase_requisition', 'purchase_order']),
    new OA\Property(property: 'procurement_document_id', type: 'string', format: 'uuid'),
])]
#[OA\Schema(schema: 'MfgStoreQualityPlanRequest', required: ['plan_code', 'name', 'inspection_type'], type: 'object', properties: [
    new OA\Property(property: 'plan_code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'inspection_type', type: 'string', enum: ['receipt', 'in_process', 'final']),
    new OA\Property(property: 'mfg_item_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'acceptance_defect_limit', type: 'integer', nullable: true),
    new OA\Property(property: 'characteristics', type: 'array', items: new OA\Items(required: ['characteristic_name'], properties: [new OA\Property(property: 'characteristic_name', type: 'string'), new OA\Property(property: 'target_value', type: 'string', format: 'decimal', nullable: true), new OA\Property(property: 'min_tolerance', type: 'string', format: 'decimal', nullable: true), new OA\Property(property: 'max_tolerance', type: 'string', format: 'decimal', nullable: true), new OA\Property(property: 'uom', type: 'string', nullable: true), new OA\Property(property: 'is_critical', type: 'boolean', nullable: true)], type: 'object'), nullable: true),
])]
#[OA\Schema(schema: 'MfgRecordInspectionRequest', required: ['quality_plan_id', 'inspection_point'], type: 'object', properties: [
    new OA\Property(property: 'quality_plan_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'inspection_point', type: 'string', enum: ['receipt', 'operation', 'final']),
    new OA\Property(property: 'production_order_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'production_order_operation_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'material_movement_id', description: 'The receipt inspected', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'sample_size', type: 'integer', nullable: true),
    new OA\Property(property: 'defect_count', type: 'integer', nullable: true),
    new OA\Property(property: 'quantity_inspected', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'quantity_rejected', type: 'string', format: 'decimal', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'characteristic_results', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'characteristic_id', type: 'string', format: 'uuid'), new OA\Property(property: 'measured_value', type: 'string', format: 'decimal')], type: 'object'), nullable: true),
])]
#[OA\Schema(schema: 'MfgStoreNonConformanceRequest', required: ['severity', 'defect_type', 'description'], type: 'object', properties: [
    new OA\Property(property: 'production_order_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'mfg_item_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'severity', type: 'string', enum: ['minor', 'major', 'critical']),
    new OA\Property(property: 'defect_type', type: 'string'),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'quantity', type: 'string', format: 'decimal', nullable: true),
])]
#[OA\Schema(schema: 'MfgResolveNonConformanceRequest', required: ['disposition', 'corrective_action'], type: 'object', properties: [
    new OA\Property(property: 'disposition', type: 'string', enum: ['use_as_is', 'rework', 'scrap', 'return_to_supplier']),
    new OA\Property(property: 'corrective_action', type: 'string'),
])]
#[OA\Schema(schema: 'MfgUpdateAccountingSettingsRequest', description: 'Account codes by role; null goes back to the module default.', type: 'object', properties: [
    new OA\Property(property: 'raw_material_account_code', type: 'string', nullable: true),
    new OA\Property(property: 'wip_account_code', type: 'string', nullable: true),
    new OA\Property(property: 'finished_goods_account_code', type: 'string', nullable: true),
    new OA\Property(property: 'labor_absorbed_account_code', type: 'string', nullable: true),
    new OA\Property(property: 'overhead_absorbed_account_code', type: 'string', nullable: true),
    new OA\Property(property: 'subcontract_accrual_account_code', type: 'string', nullable: true),
    new OA\Property(property: 'material_variance_account_code', type: 'string', nullable: true),
    new OA\Property(property: 'labor_variance_account_code', type: 'string', nullable: true),
    new OA\Property(property: 'overhead_variance_account_code', type: 'string', nullable: true),
    new OA\Property(property: 'subcontract_variance_account_code', type: 'string', nullable: true),
])]
final class ManufacturingRequestSchemas {}
