<?php

declare(strict_types=1);

namespace Modules\Planning\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the sales and operations planning endpoints.
 */
#[OA\Tag(name: 'Planning', description: 'Sales and operations planning (S&OP): scenarios and what-if copies, demand and supply plans, finite capacity check, demand-supply balance with gaps, scenario comparison, the S&OP approval cycle and the published plan')]
#[OA\Response(response: 'PlanningUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'PlanningForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'PlanningNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'PlanningUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'PlanningScenario', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'scenario_type', type: 'string', enum: ['baseline', 'what_if']),
    new OA\Property(property: 'base_scenario_id', description: 'The scenario a what-if copy was made from', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'review', 'approved', 'locked']),
    new OA\Property(property: 'horizon_start', type: 'string', format: 'date'),
    new OA\Property(property: 'horizon_end', type: 'string', format: 'date'),
    new OA\Property(property: 'submitted_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'approved_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'locked_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'locked_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'published_version', description: 'Zero until the scenario is published', type: 'integer'),
    new OA\Property(property: 'published_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'lines_count', type: 'integer'),
    new OA\Property(property: 'approvals', type: 'array', items: new OA\Items(ref: '#/components/schemas/PlanningApproval')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'PlanningScenarioRequest', type: 'object', required: ['name', 'scenario_type', 'horizon_start', 'horizon_end'], properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 160),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'scenario_type', type: 'string', enum: ['baseline', 'what_if']),
    new OA\Property(property: 'horizon_start', type: 'string', format: 'date'),
    new OA\Property(property: 'horizon_end', description: 'Not before the start, at most 36 months after it', type: 'string', format: 'date'),
])]
#[OA\Schema(schema: 'PlanningScenarioCopyRequest', type: 'object', required: ['name'], properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 160),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'demand_adjustment_percent', description: 'Scales every demand quantity: 10 adds 10 per cent', type: 'number', nullable: true, minimum: -100, maximum: 1000),
    new OA\Property(property: 'supply_adjustment_percent', description: 'Scales every supply quantity', type: 'number', nullable: true, minimum: -100, maximum: 1000),
])]
#[OA\Schema(schema: 'PlanningCycleRequest', type: 'object', properties: [
    new OA\Property(property: 'comment', description: 'Required to reject and to accept a capacity overload', type: 'string', nullable: true, maxLength: 1000),
    new OA\Property(property: 'accept_overload', description: 'Approve although a resource is overloaded (needs a comment)', type: 'boolean'),
])]
#[OA\Schema(schema: 'PlanningApproval', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'scenario_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'action', type: 'string', enum: ['submit', 'approve', 'reject', 'lock', 'publish']),
    new OA\Property(property: 'from_status', type: 'string'),
    new OA\Property(property: 'to_status', type: 'string'),
    new OA\Property(property: 'user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'comment', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'PlanningLine', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'scenario_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'line_type', type: 'string', enum: ['demand', 'supply']),
    new OA\Property(property: 'item_id', description: 'Item of the item master', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'item_code', type: 'string'),
    new OA\Property(property: 'item_name', type: 'string', nullable: true),
    new OA\Property(property: 'period', description: 'First day of the month', type: 'string', format: 'date'),
    new OA\Property(property: 'quantity', type: 'string', example: '120.000'),
    new OA\Property(property: 'source', type: 'string', enum: ['manual', 'forecast', 'copied']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'PlanningLineRequest', type: 'object', required: ['line_type', 'period', 'quantity'], properties: [
    new OA\Property(property: 'line_type', type: 'string', enum: ['demand', 'supply']),
    new OA\Property(property: 'item_id', description: 'An item of the item master: code and name are taken from it', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'item_code', description: 'Required without item_id', type: 'string', nullable: true, maxLength: 60),
    new OA\Property(property: 'item_name', type: 'string', nullable: true, maxLength: 160),
    new OA\Property(property: 'period', description: 'Any date of the month, inside the horizon', type: 'string', format: 'date'),
    new OA\Property(property: 'quantity', type: 'number', minimum: 0),
    new OA\Property(property: 'notes', type: 'string', nullable: true, maxLength: 500),
])]
#[OA\Schema(schema: 'PlanningImportForecastRequest', type: 'object', properties: [
    new OA\Property(property: 'overwrite', description: 'Replace the quantity of demand lines that already exist', type: 'boolean'),
])]
#[OA\Schema(schema: 'PlanningResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'work_center_id', description: 'Work centre of the manufacturing module', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'unit', type: 'string', example: 'hours'),
    new OA\Property(property: 'default_capacity', description: 'Capacity per month', type: 'string', example: '160.000'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'rates', type: 'array', items: new OA\Items(ref: '#/components/schemas/PlanningRate')),
])]
#[OA\Schema(schema: 'PlanningResourceRequest', type: 'object', required: ['code', 'name', 'unit', 'default_capacity'], properties: [
    new OA\Property(property: 'code', type: 'string', maxLength: 40),
    new OA\Property(property: 'name', type: 'string', maxLength: 160),
    new OA\Property(property: 'work_center_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'unit', type: 'string', maxLength: 20),
    new OA\Property(property: 'default_capacity', type: 'number', minimum: 0),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'PlanningRate', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'resource_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'item_code', type: 'string'),
    new OA\Property(property: 'load_per_unit', description: 'Capacity consumed per unit supplied', type: 'string', example: '0.50000'),
])]
#[OA\Schema(schema: 'PlanningRateRequest', type: 'object', required: ['item_code', 'load_per_unit'], properties: [
    new OA\Property(property: 'item_code', type: 'string', maxLength: 60),
    new OA\Property(property: 'load_per_unit', type: 'number', exclusiveMinimum: 0),
])]
#[OA\Schema(schema: 'PlanningConstraint', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'scenario_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'resource_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'resource_code', type: 'string', nullable: true),
    new OA\Property(property: 'period', type: 'string', format: 'date'),
    new OA\Property(property: 'available_capacity', type: 'string', example: '120.000'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'PlanningConstraintRequest', type: 'object', required: ['resource_id', 'period', 'available_capacity'], properties: [
    new OA\Property(property: 'resource_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'period', description: 'Any date of the month, inside the horizon', type: 'string', format: 'date'),
    new OA\Property(property: 'available_capacity', type: 'number', minimum: 0),
    new OA\Property(property: 'notes', type: 'string', nullable: true, maxLength: 500),
])]
#[OA\Schema(schema: 'PlanningPublication', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'scenario_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'version', type: 'integer'),
    new OA\Property(property: 'is_current', type: 'boolean'),
    new OA\Property(property: 'snapshot', description: 'Scenario, totals, items and capacity summary at publication time', type: 'object'),
    new OA\Property(property: 'published_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'published_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'PlanningBalance', type: 'object', properties: [
    new OA\Property(property: 'scenario_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'carry_over', type: 'boolean'),
    new OA\Property(property: 'periods', type: 'array', items: new OA\Items(type: 'string', format: 'date')),
    new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'item_code', type: 'string'),
        new OA\Property(property: 'item_name', type: 'string', nullable: true),
        new OA\Property(property: 'total_demand', type: 'string'),
        new OA\Property(property: 'total_supply', type: 'string'),
        new OA\Property(property: 'total_gap', description: 'Supply minus demand', type: 'string'),
        new OA\Property(property: 'ending_gap', description: 'Balance of the last month', type: 'string'),
        new OA\Property(property: 'max_shortage', description: 'Largest shortage of any month', type: 'string'),
        new OA\Property(property: 'shortage_periods', type: 'integer'),
        new OA\Property(property: 'periods', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'period', type: 'string', format: 'date'),
            new OA\Property(property: 'demand', type: 'string'),
            new OA\Property(property: 'supply', type: 'string'),
            new OA\Property(property: 'gap', type: 'string'),
            new OA\Property(property: 'balance', type: 'string'),
            new OA\Property(property: 'status', type: 'string', enum: ['shortage', 'balanced', 'surplus']),
        ])),
    ])),
    new OA\Property(property: 'totals', type: 'object', properties: [
        new OA\Property(property: 'total_demand', type: 'string'),
        new OA\Property(property: 'total_supply', type: 'string'),
        new OA\Property(property: 'total_gap', type: 'string'),
        new OA\Property(property: 'shortage_items', type: 'integer'),
        new OA\Property(property: 'surplus_items', type: 'integer'),
        new OA\Property(property: 'max_shortage', type: 'string'),
    ]),
])]
#[OA\Schema(schema: 'PlanningCapacity', type: 'object', properties: [
    new OA\Property(property: 'scenario_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'periods', type: 'array', items: new OA\Items(type: 'string', format: 'date')),
    new OA\Property(property: 'rows', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'resource_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'resource_code', type: 'string'),
        new OA\Property(property: 'resource_name', type: 'string'),
        new OA\Property(property: 'unit', type: 'string'),
        new OA\Property(property: 'period', type: 'string', format: 'date'),
        new OA\Property(property: 'load', type: 'string'),
        new OA\Property(property: 'available', type: 'string'),
        new OA\Property(property: 'source', type: 'string', enum: ['constraint', 'default']),
        new OA\Property(property: 'utilization_percent', type: 'string', nullable: true),
        new OA\Property(property: 'overload', type: 'string'),
        new OA\Property(property: 'is_overloaded', type: 'boolean'),
    ])),
    new OA\Property(property: 'summary', type: 'object', properties: [
        new OA\Property(property: 'feasible', type: 'boolean'),
        new OA\Property(property: 'overloaded_count', type: 'integer'),
        new OA\Property(property: 'max_utilization_percent', type: 'string', nullable: true),
        new OA\Property(property: 'resources', type: 'integer'),
    ]),
])]
#[OA\Schema(schema: 'PlanningComparison', type: 'object', properties: [
    new OA\Property(property: 'base', type: 'object'),
    new OA\Property(property: 'other', type: 'object'),
    new OA\Property(property: 'delta', type: 'object'),
    new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'PlanningDashboard', type: 'object', properties: [
    new OA\Property(property: 'scenarios', type: 'object', properties: [new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'by_status', type: 'object')]),
    new OA\Property(property: 'pending_approval', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'current_publication', type: 'object', nullable: true),
    new OA\Property(property: 'selected_scenario', type: 'object', nullable: true),
    new OA\Property(property: 'totals', type: 'object', nullable: true),
    new OA\Property(property: 'top_gaps', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'capacity', type: 'object'),
])]
final class PlanningOpenApi {}
