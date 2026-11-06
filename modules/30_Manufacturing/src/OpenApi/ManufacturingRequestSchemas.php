<?php

declare(strict_types=1);

namespace Modules\Manufacturing\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request payloads of the module, derived from its form requests.
 */
#[OA\Schema(
    schema: 'ManufacturingCreateBomRequest',
    description: 'Request payload validated by CreateBomRequest',
    required: ['mfg_item_id', 'name', 'components'],
    type: 'object',
    properties: [
        new OA\Property(property: 'mfg_item_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string', maxLength: 200),
        new OA\Property(property: 'revision', type: 'string', maxLength: 20, nullable: true),
        new OA\Property(property: 'bom_type', type: 'string', nullable: true, enum: ['standard', 'alternate', 'phantom']),
        new OA\Property(property: 'base_quantity', type: 'number', minimum: 0.0001, nullable: true),
        new OA\Property(property: 'uom', type: 'string', maxLength: 20, nullable: true),
        new OA\Property(property: 'effective_from', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'effective_to', type: 'string', format: 'date', nullable: true, description: 'Must be on or after `effective_from`.'),
        new OA\Property(property: 'components', type: 'array', minItems: 1, items: new OA\Items(type: 'object', required: ['component_item_id', 'quantity_per'], properties: [new OA\Property(property: 'component_item_id', type: 'string', format: 'uuid'), new OA\Property(property: 'quantity_per', type: 'number', minimum: 0.0001), new OA\Property(property: 'scrap_percentage', type: 'number', minimum: 0, maximum: 100, nullable: true), new OA\Property(property: 'yield_percentage', type: 'number', minimum: 1, maximum: 100, nullable: true), new OA\Property(property: 'is_phantom', type: 'boolean', nullable: true)])),
    ]
)]
#[OA\Schema(
    schema: 'ManufacturingReviseBomRequest',
    description: 'Request payload validated by ReviseBomRequest',
    required: ['new_revision', 'reason'],
    type: 'object',
    properties: [
        new OA\Property(property: 'new_revision', type: 'string', maxLength: 20),
        new OA\Property(property: 'reason', type: 'string', maxLength: 500),
    ]
)]
#[OA\Schema(
    schema: 'ManufacturingCreateProductionOrderRequest',
    description: 'Request payload validated by CreateProductionOrderRequest',
    required: ['mfg_item_id', 'target_quantity', 'planned_start_date', 'planned_end_date'],
    type: 'object',
    properties: [
        new OA\Property(property: 'mfg_item_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'target_quantity', type: 'number', minimum: 0.0001),
        new OA\Property(property: 'planned_start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'planned_end_date', type: 'string', format: 'date', description: 'Must be on or after `planned_start_date`.'),
        new OA\Property(property: 'bom_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'routing_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'project_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'notes', type: 'string', maxLength: 1000, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ManufacturingRunMrpRequest',
    description: 'Request payload validated by RunMrpRequest',
    required: ['horizon_start_date', 'horizon_end_date'],
    type: 'object',
    properties: [
        new OA\Property(property: 'horizon_start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'horizon_end_date', type: 'string', format: 'date', description: 'Must be after `horizon_start_date`.'),
        new OA\Property(property: 'run_type', type: 'string', nullable: true, enum: ['regenerative', 'net_change']),
        new OA\Property(property: 'item_ids', type: 'array', nullable: true, items: new OA\Items(type: 'string', format: 'uuid')),
    ]
)]
#[OA\Schema(
    schema: 'ManufacturingRecordShopFloorExecutionRequest',
    description: 'Request payload validated by RecordShopFloorExecutionRequest',
    required: ['production_order_id', 'production_order_operation_id', 'work_center_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'production_order_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'production_order_operation_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'work_center_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'operator_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'machine_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'good_units', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'scrap_units', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'downtime_reason', type: 'string', maxLength: 500, nullable: true),
        new OA\Property(property: 'labor_logs', type: 'array', nullable: true, items: new OA\Items),
        new OA\Property(property: 'machine_logs', type: 'array', nullable: true, items: new OA\Items),
    ]
)]
#[OA\Schema(
    schema: 'ManufacturingRecordQualityInspectionRequest',
    description: 'Request payload validated by RecordQualityInspectionRequest',
    required: ['quality_plan_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'quality_plan_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'production_order_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'operation_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'inspector_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'sample_size', type: 'integer', minimum: 1, nullable: true),
        new OA\Property(property: 'defect_count', type: 'integer', minimum: 0, nullable: true),
        new OA\Property(property: 'notes', type: 'string', maxLength: 1000, nullable: true),
        new OA\Property(property: 'characteristic_results', type: 'array', nullable: true, items: new OA\Items),
    ]
)]
final class ManufacturingRequestSchemas {}
