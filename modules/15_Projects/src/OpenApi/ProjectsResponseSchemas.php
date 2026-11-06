<?php

declare(strict_types=1);

namespace Modules\Projects\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response payloads of the module, derived from its API resources and models.
 */
#[OA\Schema(
    schema: 'ProjectsProjectResource',
    description: 'JSON representation produced by ProjectResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'company_id', type: 'string'),
        new OA\Property(property: 'portfolio_id', type: 'string'),
        new OA\Property(property: 'contact_id', type: 'string'),
        new OA\Property(property: 'project_number'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'project_type'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'billing_method'),
        new OA\Property(property: 'planned_start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'planned_end_date', type: 'string', format: 'date'),
        new OA\Property(property: 'actual_start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'actual_end_date', type: 'string', format: 'date'),
        new OA\Property(property: 'project_manager_user_id', type: 'string'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'contract_value', type: 'number'),
        new OA\Property(property: 'progress_percentage', type: 'number'),
        new OA\Property(property: 'health_status'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ProjectsProjectFinancialSummaryResource',
    description: 'JSON representation produced by ProjectFinancialSummaryResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'project_id', type: 'string'),
        new OA\Property(property: 'total_contract_revenue', type: 'number'),
        new OA\Property(property: 'total_invoiced_revenue', type: 'number'),
        new OA\Property(property: 'total_recognized_revenue', type: 'number'),
        new OA\Property(property: 'actual_material_cost', type: 'number'),
        new OA\Property(property: 'actual_labour_cost', type: 'number'),
        new OA\Property(property: 'actual_supplier_cost', type: 'number'),
        new OA\Property(property: 'actual_fleet_cost', type: 'number'),
        new OA\Property(property: 'actual_expense_cost', type: 'number'),
        new OA\Property(property: 'total_actual_cost', type: 'number'),
        new OA\Property(property: 'committed_po_cost', type: 'number'),
        new OA\Property(property: 'gross_profit_amount', type: 'number'),
        new OA\Property(property: 'gross_profit_percentage', type: 'number'),
        new OA\Property(property: 'earned_value_management', type: 'object', properties: [new OA\Property(property: 'planned_value_pv', type: 'number'), new OA\Property(property: 'earned_value_ev', type: 'number'), new OA\Property(property: 'budget_at_completion_bac', type: 'number'), new OA\Property(property: 'cost_performance_index_cpi', type: 'number'), new OA\Property(property: 'schedule_performance_index_spi', type: 'number'), new OA\Property(property: 'estimate_at_completion_eac', type: 'number'), new OA\Property(property: 'estimate_to_complete_etc', type: 'number'), new OA\Property(property: 'variance_at_completion_vac', type: 'number')]),
        new OA\Property(property: 'last_recalculated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ProjectsProjectBudgetResource',
    description: 'JSON representation produced by ProjectBudgetResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'project_id', type: 'string'),
        new OA\Property(property: 'current_version_number', type: 'integer', nullable: true),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'total_revenue_budget', type: 'number'),
        new OA\Property(property: 'total_cost_budget', type: 'number'),
        new OA\Property(property: 'breakdown', type: 'object', properties: [new OA\Property(property: 'labour', type: 'number'), new OA\Property(property: 'material', type: 'number'), new OA\Property(property: 'supplier', type: 'number'), new OA\Property(property: 'fleet', type: 'number'), new OA\Property(property: 'expense', type: 'number'), new OA\Property(property: 'contingency', type: 'number')]),
        new OA\Property(property: 'projected_margin_amount', type: 'number'),
        new OA\Property(property: 'projected_margin_percentage', type: 'number'),
        new OA\Property(property: 'approved_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'lines'),
    ]
)]
#[OA\Schema(
    schema: 'ProjectsProjectCostEntryResource',
    description: 'JSON representation produced by ProjectCostEntryResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'project_id', type: 'string'),
        new OA\Property(property: 'project_phase_id', type: 'string'),
        new OA\Property(property: 'project_task_id', type: 'string'),
        new OA\Property(property: 'cost_source_type'),
        new OA\Property(property: 'cost_source_id', type: 'string'),
        new OA\Property(property: 'cost_category'),
        new OA\Property(property: 'quantity', type: 'number'),
        new OA\Property(property: 'uom'),
        new OA\Property(property: 'unit_cost', type: 'number'),
        new OA\Property(property: 'total_cost', type: 'number'),
        new OA\Property(property: 'is_billable', type: 'boolean'),
        new OA\Property(property: 'is_invoiced', type: 'boolean'),
        new OA\Property(property: 'incurred_date', type: 'string', format: 'date'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ProjectsProjectChangeOrderResource',
    description: 'JSON representation produced by ProjectChangeOrderResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'project_id', type: 'string'),
        new OA\Property(property: 'order_number'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'scope_description'),
        new OA\Property(property: 'cost_impact_amount', type: 'number'),
        new OA\Property(property: 'revenue_impact_amount', type: 'number'),
        new OA\Property(property: 'schedule_impact_days', type: 'integer', nullable: true),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'approved_by_client_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'lines'),
    ]
)]
#[OA\Schema(
    schema: 'ProjectsProjectTimesheetResource',
    description: 'JSON representation produced by ProjectTimesheetResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'project_id', type: 'string'),
        new OA\Property(property: 'project_task_id', type: 'string'),
        new OA\Property(property: 'user_id', type: 'string'),
        new OA\Property(property: 'work_date', type: 'string', format: 'date'),
        new OA\Property(property: 'hours_worked', type: 'number'),
        new OA\Property(property: 'overtime_hours', type: 'number'),
        new OA\Property(property: 'is_billable', type: 'boolean'),
        new OA\Property(property: 'cost_rate', type: 'number'),
        new OA\Property(property: 'charge_rate', type: 'number'),
        new OA\Property(property: 'total_cost', type: 'number'),
        new OA\Property(property: 'total_charge', type: 'number'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'approved_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'work_summary'),
    ]
)]
#[OA\Schema(
    schema: 'ProjectsProjectRevenueRecognitionModel',
    description: 'Attributes of the Project Revenue Recognition record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'accounting_period_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'cumulative_actual_cost', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'estimated_total_cost_eac', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'cost_completion_percentage', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'cumulative_recognized_revenue', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'period_revenue_adjustment', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'is_posted_to_gl', type: 'boolean', nullable: true),
        new OA\Property(property: 'posted_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
final class ProjectsResponseSchemas {}
