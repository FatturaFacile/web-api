<?php

declare(strict_types=1);

namespace Modules\Projects\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Project',
    title: 'Enterprise Project',
    description: 'Master project entity managing WBS, scheduling, job costing, and contract execution',
    required: ['id', 'tenant_id', 'project_number', 'name', 'status', 'project_type', 'billing_method', 'contract_value']
)]
class ProjectOpenApiSchemas
{
    #[OA\Property(format: 'uuid', example: '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b')]
    public string $id;

    #[OA\Property(format: 'uuid', example: '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6c')]
    public string $tenant_id;

    #[OA\Property(example: 'PRJ-2027-001')]
    public string $project_number;

    #[OA\Property(example: 'Milano Headquarters Modernization')]
    public string $name;

    #[OA\Property(example: 'active', enum: ['draft', 'planned', 'approved', 'active', 'on_hold', 'completed', 'closed', 'cancelled'])]
    public string $status;

    #[OA\Property(example: 'fixed_price', enum: ['fixed_price', 'time_and_materials', 'cost_plus', 'internal', 'milestone_based'])]
    public string $billing_method;

    #[OA\Property(example: 'construction', enum: ['internal', 'customer', 'construction', 'consulting', 'software_dev', 'maintenance', 'capex'])]
    public string $project_type;

    #[OA\Property(example: 250000.00)]
    public float $contract_value;

    #[OA\Property(example: 45.50)]
    public float $progress_percentage;

    #[OA\Property(example: 'EUR')]
    public string $currency;

    #[OA\Property(format: 'date', example: '2027-02-01')]
    public string $planned_start_date;

    #[OA\Property(format: 'date', example: '2027-10-31')]
    public string $planned_end_date;
}

#[OA\Schema(
    schema: 'ProjectFinancialSummary',
    title: 'Project Financial & EVM Summary',
    description: 'ANSI/EIA-748 Earned Value Management metrics and accumulated cost breakdown',
    required: ['project_id', 'budget_at_completion_bac', 'actual_cost_ac', 'earned_value_ev', 'cost_performance_index_cpi']
)]
class ProjectFinancialSummarySchema
{
    #[OA\Property(format: 'uuid')]
    public string $project_id;

    #[OA\Property(example: 200000.00)]
    public float $budget_at_completion_bac;

    #[OA\Property(example: 100000.00)]
    public float $planned_value_pv;

    #[OA\Property(example: 95000.00)]
    public float $earned_value_ev;

    #[OA\Property(example: 92000.00)]
    public float $actual_cost_ac;

    #[OA\Property(example: 3000.00)]
    public float $cost_variance_cv;

    #[OA\Property(example: -5000.00)]
    public float $schedule_variance_sv;

    #[OA\Property(example: 1.03)]
    public float $cost_performance_index_cpi;

    #[OA\Property(example: 0.95)]
    public float $schedule_performance_index_spi;

    #[OA\Property(example: 194174.76)]
    public float $estimate_at_completion_eac;

    #[OA\Property(example: 102174.76)]
    public float $estimate_to_complete_etc;

    #[OA\Property(example: 5825.24)]
    public float $variance_at_completion_vac;

    #[OA\Property(example: 'green', enum: ['green', 'yellow', 'red'])]
    public string $health_status;
}

#[OA\Schema(
    schema: 'ProjectBudget',
    title: 'Project Budget Master',
    description: 'Comprehensive project cost and revenue budget with workflow approval status',
    required: ['id', 'project_id', 'status', 'total_revenue_budget', 'total_cost_budget']
)]
class ProjectBudgetSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(format: 'uuid')]
    public string $project_id;

    #[OA\Property(example: 'approved', enum: ['draft', 'pending_approval', 'approved', 'rejected', 'superseded'])]
    public string $status;

    #[OA\Property(example: 250000.00)]
    public float $total_revenue_budget;

    #[OA\Property(example: 200000.00)]
    public float $total_cost_budget;

    #[OA\Property(example: 80000.00)]
    public float $labour_budget;

    #[OA\Property(example: 60000.00)]
    public float $material_budget;

    #[OA\Property(example: 40000.00)]
    public float $supplier_budget;

    #[OA\Property(example: 10000.00)]
    public float $fleet_budget;

    #[OA\Property(example: 5000.00)]
    public float $expense_budget;

    #[OA\Property(example: 5000.00)]
    public float $contingency_budget;

    #[OA\Property(example: 50000.00)]
    public float $projected_margin_amount;

    #[OA\Property(example: 20.00)]
    public float $projected_margin_percentage;
}

#[OA\Schema(
    schema: 'ProjectCostEntry',
    title: 'Project Job Cost Entry',
    description: 'Granular job costing accumulation record with origin traceability and multi-task allocation',
    required: ['id', 'project_id', 'cost_category', 'cost_source_type', 'cost_source_id', 'total_cost', 'incurred_date']
)]
class ProjectCostEntrySchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(format: 'uuid')]
    public string $project_id;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $phase_id;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $task_id;

    #[OA\Property(example: 'material', enum: ['material', 'labour', 'supplier', 'fleet', 'expense', 'equipment'])]
    public string $cost_category;

    #[OA\Property(example: 'inventory_movement')]
    public string $cost_source_type;

    #[OA\Property(format: 'uuid')]
    public string $cost_source_id;

    #[OA\Property(example: 10.00)]
    public float $quantity;

    #[OA\Property(example: 'PCE')]
    public string $uom;

    #[OA\Property(example: 150.00)]
    public float $unit_cost;

    #[OA\Property(example: 1500.00)]
    public float $total_cost;

    #[OA\Property(format: 'date', example: '2027-02-15')]
    public string $incurred_date;

    #[OA\Property(example: true)]
    public bool $is_billable;
}

#[OA\Schema(
    schema: 'ProjectTimesheet',
    title: 'Project Timesheet Record',
    description: 'Labour hour logging with burdened cost and billing rates',
    required: ['id', 'project_id', 'user_id', 'work_date', 'hours_worked', 'status']
)]
class ProjectTimesheetSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(format: 'uuid')]
    public string $project_id;

    #[OA\Property(format: 'uuid')]
    public string $user_id;

    #[OA\Property(format: 'date', example: '2027-03-01')]
    public string $work_date;

    #[OA\Property(example: 8.00)]
    public float $hours_worked;

    #[OA\Property(example: 0.00)]
    public float $overtime_hours;

    #[OA\Property(example: 45.00)]
    public float $cost_rate;

    #[OA\Property(example: 95.00)]
    public float $charge_rate;

    #[OA\Property(example: 360.00)]
    public float $total_cost;

    #[OA\Property(example: 760.00)]
    public float $total_charge;

    #[OA\Property(example: 'approved', enum: ['draft', 'submitted', 'approved', 'rejected', 'posted'])]
    public string $status;
}

#[OA\Schema(
    schema: 'ProjectChangeOrder',
    title: 'Project Change Order & Scope Variation',
    description: 'Contract amendment impacting baseline cost, revenue, and schedule',
    required: ['id', 'project_id', 'order_number', 'title', 'cost_impact_amount', 'status']
)]
class ProjectChangeOrderSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(format: 'uuid')]
    public string $project_id;

    #[OA\Property(example: 'CO-001')]
    public string $order_number;

    #[OA\Property(example: 'Structural Reinforcement Specification Change')]
    public string $title;

    #[OA\Property(example: 15000.00)]
    public float $cost_impact_amount;

    #[OA\Property(example: 18500.00)]
    public float $revenue_impact_amount;

    #[OA\Property(example: 14)]
    public int $schedule_impact_days;

    #[OA\Property(example: 'customer_approved', enum: ['draft', 'pending_approval', 'internal_approved', 'customer_approved', 'rejected', 'implemented'])]
    public string $status;
}

#[OA\Schema(
    schema: 'ProjectRevenueRecognition',
    title: 'IFRS 15 / ASC 606 Revenue Recognition',
    description: 'Percentage-of-Completion (PoC) revenue and WIP accounting adjustments',
    required: ['id', 'project_id', 'accounting_period_date', 'recognition_method', 'cost_completion_percentage', 'cumulative_revenue']
)]
class ProjectRevenueRecognitionSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(format: 'uuid')]
    public string $project_id;

    #[OA\Property(format: 'date', example: '2027-03-31')]
    public string $accounting_period_date;

    #[OA\Property(example: 'percentage_of_completion', enum: ['percentage_of_completion', 'completed_contract', 'milestone', 'time_and_materials'])]
    public string $recognition_method;

    #[OA\Property(example: 45.00)]
    public float $cost_completion_percentage;

    #[OA\Property(example: 112500.00)]
    public float $cumulative_revenue;

    #[OA\Property(example: 25000.00)]
    public float $period_revenue_adjustment;

    #[OA\Property(example: 15000.00)]
    public float $wip_balance;
}
