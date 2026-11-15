<?php

declare(strict_types=1);

namespace Modules\Wms\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the Warehouse management (WMS) endpoints.
 */
#[OA\Tag(name: 'WMS', description: 'Advanced warehouse management on top of the inventory: RF task queue, slotting rules and recommendations, cross-docking, dock doors and appointments, wave planning and the warehouse dashboard')]
#[OA\Response(response: 'WmsUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'WmsForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'WmsNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'WmsUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'WmsDockDoor', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string', maxLength: 30),
    new OA\Property(property: 'name', type: 'string', maxLength: 120, nullable: true),
    new OA\Property(property: 'direction', type: 'string', enum: ['inbound', 'outbound', 'both']),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'WmsDockDoorRequest', type: 'object', required: ['warehouse_id', 'code'], properties: [
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string', maxLength: 30),
    new OA\Property(property: 'name', type: 'string', maxLength: 120, nullable: true),
    new OA\Property(property: 'direction', type: 'string', enum: ['inbound', 'outbound', 'both']),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'WmsWaveRule', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string', maxLength: 120),
    new OA\Property(property: 'min_priority', description: 'Only tasks with at least this priority join the wave', type: 'integer', nullable: true),
    new OA\Property(property: 'due_within_hours', description: 'Only tasks due within this many hours (or without a due date) join the wave', type: 'integer', nullable: true),
    new OA\Property(property: 'reference_prefix', description: 'Only tasks whose reference starts with this text join the wave (for example a carrier code)', type: 'string', maxLength: 60, nullable: true),
    new OA\Property(property: 'min_tasks', type: 'integer'),
    new OA\Property(property: 'max_tasks', type: 'integer'),
    new OA\Property(property: 'priority', type: 'integer'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'WmsWaveRuleRequest', type: 'object', required: ['warehouse_id', 'name'], properties: [
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string', maxLength: 120),
    new OA\Property(property: 'min_priority', description: 'Only tasks with at least this priority join the wave', type: 'integer', nullable: true),
    new OA\Property(property: 'due_within_hours', description: 'Only tasks due within this many hours (or without a due date) join the wave', type: 'integer', nullable: true),
    new OA\Property(property: 'reference_prefix', description: 'Only tasks whose reference starts with this text join the wave (for example a carrier code)', type: 'string', maxLength: 60, nullable: true),
    new OA\Property(property: 'min_tasks', type: 'integer'),
    new OA\Property(property: 'max_tasks', type: 'integer'),
    new OA\Property(property: 'priority', type: 'integer'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'WmsWave', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'wave_rule_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'number', type: 'string', maxLength: 40),
    new OA\Property(property: 'status', type: 'string', enum: ['planned', 'released', 'completed', 'cancelled']),
    new OA\Property(property: 'task_count', type: 'integer'),
    new OA\Property(property: 'released_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'WmsTask', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'task_type', type: 'string', enum: ['putaway', 'pick', 'replenish', 'count', 'move']),
    new OA\Property(property: 'status', type: 'string', enum: ['open', 'assigned', 'in_progress', 'completed', 'short_completed', 'skipped', 'cancelled']),
    new OA\Property(property: 'priority', description: '0 to 100, the higher the more urgent', type: 'integer'),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'source_bin_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'destination_bin_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'planned_quantity', type: 'string', example: '10.00'),
    new OA\Property(property: 'confirmed_quantity', type: 'string', example: '10.00'),
    new OA\Property(property: 'assigned_to', description: 'A member of the company', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'wave_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'reference', type: 'string', maxLength: 100, nullable: true),
    new OA\Property(property: 'due_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'short_reason', type: 'string', maxLength: 255, nullable: true),
    new OA\Property(property: 'skip_reason', type: 'string', maxLength: 255, nullable: true),
    new OA\Property(property: 'skip_count', type: 'integer'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'remaining_quantity', type: 'string', example: '0.0000'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'WmsTaskRequest', type: 'object', required: ['warehouse_id', 'task_type', 'planned_quantity'], properties: [
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'task_type', type: 'string', enum: ['putaway', 'pick', 'replenish', 'count', 'move']),
    new OA\Property(property: 'priority', description: '0 to 100, the higher the more urgent', type: 'integer'),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'source_bin_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'destination_bin_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'planned_quantity', type: 'string', example: '10.00'),
    new OA\Property(property: 'assigned_to', description: 'A member of the company', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'reference', type: 'string', maxLength: 100, nullable: true),
    new OA\Property(property: 'due_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'WmsSlottingRule', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string', maxLength: 120),
    new OA\Property(property: 'abc_class', type: 'string', enum: ['A', 'B', 'C'], nullable: true),
    new OA\Property(property: 'min_velocity', description: 'Picks per day, inclusive', type: 'string', example: '10.00', nullable: true),
    new OA\Property(property: 'max_velocity', description: 'Picks per day, exclusive', type: 'string', example: '10.00', nullable: true),
    new OA\Property(property: 'target_bin_type', type: 'string', enum: ['storage', 'picking', 'bulk', 'receiving', 'staging', 'shipping', 'quarantine', 'transit']),
    new OA\Property(property: 'target_zone_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'priority', type: 'integer'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'WmsSlottingRuleRequest', type: 'object', required: ['warehouse_id', 'name', 'target_bin_type'], properties: [
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string', maxLength: 120),
    new OA\Property(property: 'abc_class', type: 'string', enum: ['A', 'B', 'C'], nullable: true),
    new OA\Property(property: 'min_velocity', description: 'Picks per day, inclusive', type: 'string', example: '10.00', nullable: true),
    new OA\Property(property: 'max_velocity', description: 'Picks per day, exclusive', type: 'string', example: '10.00', nullable: true),
    new OA\Property(property: 'target_bin_type', type: 'string', enum: ['storage', 'picking', 'bulk', 'receiving', 'staging', 'shipping', 'quarantine', 'transit']),
    new OA\Property(property: 'target_zone_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'priority', type: 'integer'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'WmsSlottingRecommendation', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'slotting_rule_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'abc_class', type: 'string', enum: ['A', 'B', 'C']),
    new OA\Property(property: 'velocity', description: 'Picks per day over the analysed period', type: 'string', example: '10.00'),
    new OA\Property(property: 'period_days', type: 'integer'),
    new OA\Property(property: 'from_bin_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'to_bin_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'target_bin_type', type: 'string', maxLength: 30),
    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'accepted', 'rejected']),
    new OA\Property(property: 'task_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'decided_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'reason', type: 'string', maxLength: 255, nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'WmsCrossDockOrder', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'number', type: 'string', maxLength: 40),
    new OA\Property(property: 'inbound_reference', type: 'string', maxLength: 100),
    new OA\Property(property: 'outbound_reference', type: 'string', maxLength: 100),
    new OA\Property(property: 'inbound_door_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'outbound_door_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['planned', 'received', 'shipped', 'cancelled']),
    new OA\Property(property: 'planned_inbound_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'planned_outbound_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'received_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'shipped_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'match_status', type: 'string', enum: ['unmatched', 'partial', 'matched']),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsCrossDockLine')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'WmsCrossDockLine', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'cross_dock_order_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'inbound_quantity', type: 'string', example: '10.00'),
    new OA\Property(property: 'received_quantity', type: 'string', example: '10.00'),
    new OA\Property(property: 'outbound_quantity', type: 'string', example: '10.00'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'WmsDockAppointment', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'dock_door_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'carrier_name', type: 'string', maxLength: 160),
    new OA\Property(property: 'reference', type: 'string', maxLength: 100, nullable: true),
    new OA\Property(property: 'direction', type: 'string', enum: ['inbound', 'outbound']),
    new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'ends_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'status', type: 'string', enum: ['scheduled', 'checked_in', 'completed', 'cancelled', 'no_show']),
    new OA\Property(property: 'checked_in_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'cross_dock_order_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'WmsDockAppointmentRequest', type: 'object', required: ['dock_door_id', 'carrier_name', 'direction', 'starts_at', 'ends_at'], properties: [
    new OA\Property(property: 'dock_door_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'carrier_name', type: 'string', maxLength: 160),
    new OA\Property(property: 'reference', type: 'string', maxLength: 100, nullable: true),
    new OA\Property(property: 'direction', type: 'string', enum: ['inbound', 'outbound']),
    new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'ends_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'cross_dock_order_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'WmsCrossDockOrderRequest', type: 'object', required: ['warehouse_id', 'inbound_reference', 'outbound_reference'], properties: [
    new OA\Property(property: 'warehouse_id', description: 'Create only', type: 'string', format: 'uuid'),
    new OA\Property(property: 'inbound_reference', type: 'string', maxLength: 100),
    new OA\Property(property: 'outbound_reference', type: 'string', maxLength: 100),
    new OA\Property(property: 'inbound_door_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'outbound_door_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'planned_inbound_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'planned_outbound_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'lines', description: 'One line per stock item; required on creation', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'), new OA\Property(property: 'inbound_quantity', type: 'string', example: '10'), new OA\Property(property: 'outbound_quantity', type: 'string', example: '10')])),
])]
#[OA\Schema(schema: 'WmsDashboard', type: 'object', properties: [
    new OA\Property(property: 'period', type: 'object', properties: [new OA\Property(property: 'from', type: 'string', format: 'date'), new OA\Property(property: 'to', type: 'string', format: 'date'), new OA\Property(property: 'days', type: 'integer')]),
    new OA\Property(property: 'throughput', type: 'object', properties: [new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'by_type', type: 'object'), new OA\Property(property: 'per_day', type: 'array', items: new OA\Items(type: 'object'))]),
    new OA\Property(property: 'open_tasks', type: 'object', properties: [new OA\Property(property: 'by_status', type: 'object'), new OA\Property(property: 'by_type', type: 'object'), new OA\Property(property: 'total', type: 'integer')]),
    new OA\Property(property: 'pick_short_rate_percent', description: 'Share of closed pick tasks closed short, null without closed picks', type: 'string', nullable: true, example: '12.50'),
    new OA\Property(property: 'dock_utilization', type: 'object', properties: [new OA\Property(property: 'overall_percent', type: 'string', example: '35.20'), new OA\Property(property: 'doors', type: 'array', items: new OA\Items(type: 'object'))]),
    new OA\Property(property: 'pending_recommendations', type: 'integer'),
    new OA\Property(property: 'open_waves', type: 'integer'),
    new OA\Property(property: 'open_cross_docks', type: 'integer'),
])]
final class WmsOpenApi {}
