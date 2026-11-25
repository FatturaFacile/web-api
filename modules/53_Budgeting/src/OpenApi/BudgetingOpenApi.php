<?php

declare(strict_types=1);

namespace Modules\Budgeting\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the planning and budgeting endpoints.
 */
#[OA\Tag(name: 'Budgeting', description: 'Planning and budgeting (controlling): budget versions per fiscal year, budget lines by account, cost centre, project and period, driver-based planning, allocation rules, rolling forecast, approval workflow and budget versus actual')]
#[OA\Response(response: 'BudgetingUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'BudgetingForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'BudgetingNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'BudgetingUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'BudgetVersion', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'fiscal_year', type: 'integer', example: 2028),
    new OA\Property(property: 'year_start', description: 'First day of fiscal period 1', type: 'string', format: 'date'),
    new OA\Property(property: 'version_type', type: 'string', enum: ['budget', 'revised', 'forecast', 'rolling_forecast', 'scenario']),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'submitted', 'approved', 'locked']),
    new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
    new OA\Property(property: 'base_version_id', description: 'The version this one was copied or re-forecast from', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'reforecast_from_period', description: 'First re-forecast period of a rolling forecast', type: 'integer', nullable: true),
    new OA\Property(property: 'seasonal_weights', description: 'Twelve weights of the seasonal allocation', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
    new OA\Property(property: 'submitted_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'approved_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'locked_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'locked_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'lines_count', type: 'integer'),
    new OA\Property(property: 'drivers_count', type: 'integer'),
    new OA\Property(property: 'total_amount', description: 'Sum of the lines', type: 'string', example: '120000.00'),
    new OA\Property(property: 'approvals', type: 'array', items: new OA\Items(ref: '#/components/schemas/BudgetApproval')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'BudgetVersionRequest', type: 'object', required: ['name', 'fiscal_year', 'version_type', 'currency'], properties: [
    new OA\Property(property: 'name', description: 'Unique within the fiscal year', type: 'string', maxLength: 160),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'fiscal_year', type: 'integer'),
    new OA\Property(property: 'year_start', description: 'Defaults to the first of January of the fiscal year', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'version_type', type: 'string', enum: ['budget', 'revised', 'forecast', 'rolling_forecast', 'scenario']),
    new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3),
    new OA\Property(property: 'seasonal_weights', description: 'Exactly twelve non-negative weights, at least one above zero', type: 'array', items: new OA\Items(type: 'number'), nullable: true),
])]
#[OA\Schema(schema: 'BudgetVersionCopyRequest', type: 'object', required: ['name'], properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 160),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'version_type', type: 'string', enum: ['budget', 'forecast'], default: 'forecast'),
])]
#[OA\Schema(schema: 'BudgetReforecastRequest', type: 'object', required: ['name', 'from_period'], properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 160),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'from_period', description: 'First period to re-forecast: the earlier ones take the actuals', type: 'integer', minimum: 1, maximum: 12),
    new OA\Property(property: 'mode', type: 'string', enum: ['keep_annual_target', 'keep_remaining'], default: 'keep_annual_target'),
    new OA\Property(property: 'adjustment_percent', description: 'Scales the re-forecast periods', type: 'number', nullable: true, minimum: -100, maximum: 1000),
])]
#[OA\Schema(schema: 'BudgetApprovalRequest', type: 'object', properties: [
    new OA\Property(property: 'comment', description: 'Required to reject', type: 'string', nullable: true, maxLength: 1000),
])]
#[OA\Schema(schema: 'BudgetApproval', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'version_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'action', type: 'string', enum: ['submit', 'approve', 'reject', 'lock']),
    new OA\Property(property: 'from_status', type: 'string'),
    new OA\Property(property: 'to_status', type: 'string'),
    new OA\Property(property: 'user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'comment', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'BudgetLine', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'version_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'account_id', description: 'Account of the chart of accounts', type: 'string', format: 'uuid'),
    new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'project_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'period', description: 'Fiscal period, 1 to 12', type: 'integer'),
    new OA\Property(property: 'amount', type: 'string', example: '1000.00'),
    new OA\Property(property: 'source', type: 'string', enum: ['manual', 'driver', 'spread', 'reforecast', 'actual']),
    new OA\Property(property: 'driver_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'BudgetLineRequest', type: 'object', required: ['account_id', 'period', 'amount'], properties: [
    new OA\Property(property: 'account_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'project_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'period', type: 'integer', minimum: 1, maximum: 12),
    new OA\Property(property: 'amount', type: 'number', minimum: 0),
    new OA\Property(property: 'notes', type: 'string', nullable: true, maxLength: 500),
])]
#[OA\Schema(schema: 'BudgetSpreadRequest', type: 'object', required: ['account_id', 'total_amount', 'method', 'start_period', 'end_period'], properties: [
    new OA\Property(property: 'account_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'project_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'total_amount', type: 'number', minimum: 0),
    new OA\Property(property: 'method', type: 'string', enum: ['even', 'seasonal', 'percentage']),
    new OA\Property(property: 'start_period', type: 'integer', minimum: 1, maximum: 12),
    new OA\Property(property: 'end_period', type: 'integer', minimum: 1, maximum: 12),
    new OA\Property(property: 'percentages', description: 'One per period of the range, adding up to 100 (method percentage)', type: 'array', items: new OA\Items(type: 'number'), nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true, maxLength: 500),
])]
#[OA\Schema(schema: 'BudgetDriver', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'version_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'account_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'project_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'quantity', type: 'string', example: '10.000'),
    new OA\Property(property: 'rate', type: 'string', example: '2500.0000'),
    new OA\Property(property: 'total_amount', description: 'Quantity times rate', type: 'string', example: '25000.00'),
    new OA\Property(property: 'spread_method', type: 'string', enum: ['even', 'seasonal', 'percentage']),
    new OA\Property(property: 'percentages', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
    new OA\Property(property: 'start_period', type: 'integer'),
    new OA\Property(property: 'end_period', type: 'integer'),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'BudgetDriverRequest', type: 'object', required: ['name', 'account_id', 'quantity', 'rate', 'spread_method', 'start_period', 'end_period'], properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 160),
    new OA\Property(property: 'account_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'project_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'quantity', type: 'number', minimum: 0),
    new OA\Property(property: 'rate', type: 'number', minimum: 0),
    new OA\Property(property: 'spread_method', type: 'string', enum: ['even', 'seasonal', 'percentage']),
    new OA\Property(property: 'percentages', description: 'One per period of the range, adding up to 100 (spread_method percentage)', type: 'array', items: new OA\Items(type: 'number'), nullable: true),
    new OA\Property(property: 'start_period', type: 'integer', minimum: 1, maximum: 12),
    new OA\Property(property: 'end_period', type: 'integer', minimum: 1, maximum: 12),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'BudgetVariance', type: 'object', properties: [
    new OA\Property(property: 'version_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'through_period', type: 'integer'),
    new OA\Property(property: 'group_by', type: 'string', enum: ['account', 'cost_center', 'project', 'period']),
    new OA\Property(property: 'rows', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'key', description: 'Id of the account, cost centre or project, or the period number', type: 'string'),
        new OA\Property(property: 'label', type: 'string'),
        new OA\Property(property: 'code', type: 'string', nullable: true),
        new OA\Property(property: 'budget', type: 'string'),
        new OA\Property(property: 'actual', type: 'string'),
        new OA\Property(property: 'variance', description: 'Actual minus budget', type: 'string'),
        new OA\Property(property: 'variance_percent', type: 'string', nullable: true),
        new OA\Property(property: 'is_favorable', type: 'boolean'),
        new OA\Property(property: 'status', type: 'string', enum: ['over', 'under', 'on_budget']),
    ])),
    new OA\Property(property: 'totals', type: 'object', properties: [
        new OA\Property(property: 'budget', type: 'string'),
        new OA\Property(property: 'actual', type: 'string'),
        new OA\Property(property: 'variance', type: 'string'),
        new OA\Property(property: 'variance_percent', type: 'string', nullable: true),
        new OA\Property(property: 'adverse', type: 'string'),
        new OA\Property(property: 'favorable', type: 'string'),
    ]),
])]
#[OA\Schema(schema: 'BudgetingDashboard', type: 'object', properties: [
    new OA\Property(property: 'versions', type: 'object', properties: [new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'by_status', type: 'object'), new OA\Property(property: 'by_type', type: 'object')]),
    new OA\Property(property: 'pending_approval', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'selected_version', type: 'object', nullable: true),
    new OA\Property(property: 'through_period', type: 'integer'),
    new OA\Property(property: 'totals', type: 'object', nullable: true),
    new OA\Property(property: 'series', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'top_overruns', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'drivers_count', type: 'integer'),
])]
final class BudgetingOpenApi {}
