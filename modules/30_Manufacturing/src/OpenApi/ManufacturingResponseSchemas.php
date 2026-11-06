<?php

declare(strict_types=1);

namespace Modules\Manufacturing\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response payloads of the module, derived from its API resources and models.
 */
#[OA\Schema(
    schema: 'ManufacturingMfgBomResource',
    description: 'JSON representation produced by MfgBomResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'bom_number'),
        new OA\Property(property: 'mfg_item_id', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'revision'),
        new OA\Property(property: 'version', type: 'integer', nullable: true),
        new OA\Property(property: 'bom_type'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'base_quantity', type: 'number'),
        new OA\Property(property: 'uom'),
        new OA\Property(property: 'is_locked', type: 'boolean'),
        new OA\Property(property: 'components_count', type: 'integer'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ManufacturingMfgProductionOrderResource',
    description: 'JSON representation produced by MfgProductionOrderResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'order_number'),
        new OA\Property(property: 'mfg_item_id', type: 'string'),
        new OA\Property(property: 'bom_id', type: 'string'),
        new OA\Property(property: 'routing_id', type: 'string'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'target_quantity', type: 'number'),
        new OA\Property(property: 'completed_quantity', type: 'number'),
        new OA\Property(property: 'scrapped_quantity', type: 'number'),
        new OA\Property(property: 'uom'),
        new OA\Property(property: 'planned_start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'planned_end_date', type: 'string', format: 'date'),
        new OA\Property(property: 'standard_cost_total', type: 'number'),
        new OA\Property(property: 'actual_cost_total', type: 'number'),
        new OA\Property(property: 'variance_total', type: 'number'),
        new OA\Property(property: 'is_locked', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ManufacturingMfgMrpRunResource',
    description: 'JSON representation produced by MfgMrpRunResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'run_number'),
        new OA\Property(property: 'run_type'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'horizon_start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'horizon_end_date', type: 'string', format: 'date'),
        new OA\Property(property: 'total_items_processed', type: 'integer', nullable: true),
        new OA\Property(property: 'total_planned_orders', type: 'integer', nullable: true),
        new OA\Property(property: 'total_shortages', type: 'integer', nullable: true),
        new OA\Property(property: 'executed_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ManufacturingMfgMrpPlannedOrderModel',
    description: 'Attributes of the Mfg Mrp Planned Order record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'quantity', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'start_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ManufacturingMfgMrpShortageModel',
    description: 'Attributes of the Mfg Mrp Shortage record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'required_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'gross_requirement', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'available_inventory', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'scheduled_receipts', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'safety_stock', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'net_shortage', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ManufacturingMfgShopFloorExecutionResource',
    description: 'JSON representation produced by MfgShopFloorExecutionResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'execution_number'),
        new OA\Property(property: 'production_order_id', type: 'string'),
        new OA\Property(property: 'production_order_operation_id', type: 'string'),
        new OA\Property(property: 'work_center_id', type: 'string'),
        new OA\Property(property: 'operator_id', type: 'string'),
        new OA\Property(property: 'machine_id', type: 'string'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'good_units', type: 'number'),
        new OA\Property(property: 'scrap_units', type: 'number'),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'downtime_reason'),
    ]
)]
#[OA\Schema(
    schema: 'ManufacturingMfgQualityInspectionResource',
    description: 'JSON representation produced by MfgQualityInspectionResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'inspection_number'),
        new OA\Property(property: 'quality_plan_id', type: 'string'),
        new OA\Property(property: 'production_order_id', type: 'string'),
        new OA\Property(property: 'operation_id', type: 'string'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'sample_size', type: 'integer', nullable: true),
        new OA\Property(property: 'defect_count', type: 'integer', nullable: true),
        new OA\Property(property: 'inspected_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'notes', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'ManufacturingMfgQualityNonConformanceModel',
    description: 'Attributes of the Mfg Quality Non Conformance record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'resolved_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
final class ManufacturingResponseSchemas {}
