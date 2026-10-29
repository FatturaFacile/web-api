<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'project_portfolios',
            'project_templates',
            'projects',
            'project_phases',
            'project_milestones',
            'project_tasks',
            'project_task_dependencies',
            'project_task_assignments',
            'project_resources',
            'resource_capacity_profiles',
            'resource_calendars',
            'resource_allocations',
            'project_budgets',
            'project_budget_versions',
            'project_budget_lines',
            'project_budget_approvals',
            'project_change_orders',
            'project_change_order_lines',
            'project_variation_requests',
            'project_cost_entries',
            'project_cost_allocations',
            'project_cost_snapshots',
            'project_financial_summaries',
            'project_profitability_snapshots',
            'project_revenue_recognition',
            'project_billing_events',
            'project_invoice_links',
            'project_audit_entries',
            'project_timesheets',
            'project_risks',
            'project_issues',
            'project_notes',
            'project_documents',
            'project_status_histories',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        // No-op for forward roll
    }
};
