<?php

declare(strict_types=1);

namespace Modules\Projects\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request payloads of the module, derived from its form requests.
 */
#[OA\Schema(
    schema: 'ProjectsStoreProjectRequest',
    description: 'Request payload validated by StoreProjectRequest',
    required: ['contact_id', 'project_number', 'name', 'project_type', 'billing_method', 'planned_start_date', 'planned_end_date', 'project_manager_user_id', 'contract_value'],
    type: 'object',
    properties: [
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'portfolio_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'parent_project_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'project_number', type: 'string', maxLength: 100),
        new OA\Property(property: 'name', type: 'string', maxLength: 255),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'project_type', type: 'string', enum: ['fixed_price', 'time_and_material', 'time_and_materials', 'milestone', 'internal_rd', 'construction', 'consulting', 'software_dev', 'internal', 'customer', 'maintenance', 'capex']),
        new OA\Property(property: 'billing_method', type: 'string', enum: ['fixed_price', 'time_and_materials', 'time_material', 'cost_plus', 'internal', 'milestone_based', 'percentage_of_completion', 'milestone']),
        new OA\Property(property: 'planned_start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'planned_end_date', type: 'string', format: 'date', description: 'Must be on or after `planned_start_date`.'),
        new OA\Property(property: 'project_manager_user_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'contract_value', type: 'number'),
        new OA\Property(property: 'phases', type: 'array', nullable: true, items: new OA\Items),
        new OA\Property(property: 'milestones', type: 'array', nullable: true, items: new OA\Items),
    ]
)]
#[OA\Schema(
    schema: 'ProjectsUpdateProjectRequest',
    description: 'Request payload validated by UpdateProjectRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 255),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'planned', 'approved', 'active', 'on_hold', 'completed', 'closed', 'cancelled']),
        new OA\Property(property: 'planned_start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'planned_end_date', type: 'string', format: 'date'),
        new OA\Property(property: 'actual_start_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'actual_end_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'contract_value', type: 'number'),
        new OA\Property(property: 'progress_percentage', type: 'number', minimum: 0, maximum: 100),
        new OA\Property(property: 'health_status', type: 'string', enum: ['green', 'yellow', 'red']),
        new OA\Property(property: 'project_manager_user_id', type: 'string', format: 'uuid'),
    ]
)]
#[OA\Schema(
    schema: 'ProjectsStoreProjectBudgetRequest',
    description: 'Request payload validated by StoreProjectBudgetRequest',
    required: ['total_revenue_budget', 'total_cost_budget'],
    type: 'object',
    properties: [
        new OA\Property(property: 'total_revenue_budget', type: 'number'),
        new OA\Property(property: 'total_cost_budget', type: 'number'),
        new OA\Property(property: 'labour_budget', type: 'number', nullable: true),
        new OA\Property(property: 'material_budget', type: 'number', nullable: true),
        new OA\Property(property: 'supplier_budget', type: 'number', nullable: true),
        new OA\Property(property: 'fleet_budget', type: 'number', nullable: true),
        new OA\Property(property: 'expense_budget', type: 'number', nullable: true),
        new OA\Property(property: 'contingency_budget', type: 'number', nullable: true),
        new OA\Property(property: 'revision_reason', type: 'string', nullable: true),
        new OA\Property(property: 'lines', type: 'array', nullable: true, items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'cost_category', type: 'string', enum: ['labour', 'material', 'supplier', 'fleet', 'expense', 'contingency'], description: 'Required with `lines`.'), new OA\Property(property: 'description', type: 'string', description: 'Required with `lines`.'), new OA\Property(property: 'planned_quantity', type: 'number', description: 'Required with `lines`.'), new OA\Property(property: 'planned_unit_cost', type: 'number', description: 'Required with `lines`.'), new OA\Property(property: 'planned_total_cost', type: 'number', description: 'Required with `lines`.')])),
    ]
)]
#[OA\Schema(
    schema: 'ProjectsStoreProjectCostEntryRequest',
    description: 'Request payload validated by StoreProjectCostEntryRequest',
    required: ['cost_category', 'cost_source_type', 'cost_source_id', 'quantity', 'unit_cost', 'total_cost', 'incurred_date'],
    type: 'object',
    properties: [
        new OA\Property(property: 'cost_category', type: 'string', enum: ['material', 'labour', 'supplier', 'fleet', 'expense', 'equipment']),
        new OA\Property(property: 'cost_source_type', type: 'string'),
        new OA\Property(property: 'cost_source_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'quantity', type: 'number'),
        new OA\Property(property: 'unit_cost', type: 'number'),
        new OA\Property(property: 'total_cost', type: 'number'),
        new OA\Property(property: 'incurred_date', type: 'string', format: 'date'),
        new OA\Property(property: 'phase_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'task_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'uom', type: 'string', maxLength: 20, nullable: true),
        new OA\Property(property: 'is_billable', type: 'boolean', nullable: true),
        new OA\Property(property: 'allocations', type: 'array', nullable: true, items: new OA\Items),
    ]
)]
#[OA\Schema(
    schema: 'ProjectsStoreProjectExpenseRequest',
    description: 'Request payload validated by StoreProjectExpenseRequest',
    required: ['expense_category', 'description', 'amount', 'incurred_date'],
    type: 'object',
    properties: [
        new OA\Property(property: 'expense_category', type: 'string', enum: ['travel_lodging', 'meals_subsistence', 'licensing_permits', 'site_rentals', 'laboratory_testing', 'expense', 'subcontractor', 'equipment']),
        new OA\Property(property: 'description', type: 'string', maxLength: 255),
        new OA\Property(property: 'amount', type: 'number'),
        new OA\Property(property: 'incurred_date', type: 'string', format: 'date'),
        new OA\Property(property: 'phase_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'task_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'receipt_document_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'is_billable', type: 'boolean', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ProjectsStoreChangeOrderRequest',
    description: 'Request payload validated by StoreChangeOrderRequest',
    required: ['order_number', 'title', 'scope_description', 'cost_impact_amount'],
    type: 'object',
    properties: [
        new OA\Property(property: 'order_number', type: 'string', maxLength: 100),
        new OA\Property(property: 'title', type: 'string', maxLength: 255),
        new OA\Property(property: 'scope_description', type: 'string'),
        new OA\Property(property: 'cost_impact_amount', type: 'number'),
        new OA\Property(property: 'revenue_impact_amount', type: 'number', nullable: true),
        new OA\Property(property: 'schedule_impact_days', type: 'integer', nullable: true),
        new OA\Property(property: 'lines', type: 'array', nullable: true, items: new OA\Items),
    ]
)]
#[OA\Schema(
    schema: 'ProjectsStoreTimesheetRequest',
    description: 'Request payload validated by StoreTimesheetRequest',
    required: ['user_id', 'work_date', 'hours_worked'],
    type: 'object',
    properties: [
        new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'work_date', type: 'string', format: 'date'),
        new OA\Property(property: 'hours_worked', type: 'number'),
        new OA\Property(property: 'task_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'overtime_hours', type: 'number', nullable: true),
        new OA\Property(property: 'is_billable', type: 'boolean', nullable: true),
        new OA\Property(property: 'custom_cost_rate', type: 'number', nullable: true),
        new OA\Property(property: 'custom_charge_rate', type: 'number', nullable: true),
        new OA\Property(property: 'work_summary', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ProjectsStoreRevenueRecognitionRequest',
    description: 'Request payload validated by StoreRevenueRecognitionRequest',
    required: ['accounting_period_date'],
    type: 'object',
    properties: [
        new OA\Property(property: 'accounting_period_date', type: 'string', format: 'date'),
        new OA\Property(property: 'override_completion_percentage', type: 'number', minimum: 0, maximum: 100, nullable: true),
    ]
)]
final class ProjectsRequestSchemas {}
