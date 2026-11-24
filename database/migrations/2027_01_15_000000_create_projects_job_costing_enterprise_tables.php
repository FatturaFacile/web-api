<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Project Portfolios
        if (! Schema::hasTable('project_portfolios')) {
            Schema::create('project_portfolios', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 50);
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->uuid('portfolio_manager_user_id');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('portfolio_manager_user_id')->references('id')->on('users')->restrictOnDelete();
                $table->unique(['tenant_id', 'code'], 'uq_portfolio_code');
            });
        }

        // 2. Project Templates
        if (! Schema::hasTable('project_templates')) {
            Schema::create('project_templates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 50);
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->jsonb('default_wbs_structure')->default('[]');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code'], 'uq_proj_template_code');
            });
        }

        // 3. Projects Master Table
        if (! Schema::hasTable('projects')) {
            Schema::create('projects', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('branch_id')->nullable()->index();
                $table->uuid('cost_center_id')->nullable()->index();
                $table->uuid('portfolio_id')->nullable();
                $table->uuid('parent_project_id')->nullable();
                $table->uuid('contact_id');
                $table->string('project_number', 100);
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->string('project_type', 50)->default('fixed_price'); // fixed_price, time_and_material, milestone, internal_rd, construction, consulting
                $table->string('status', 50)->default('draft'); // draft, planned, approved, active, on_hold, completed, closed, cancelled
                $table->string('billing_method', 50)->default('percentage_of_completion'); // percentage_of_completion, milestone, time_material
                $table->date('planned_start_date');
                $table->date('planned_end_date');
                $table->date('actual_start_date')->nullable();
                $table->date('actual_end_date')->nullable();
                $table->uuid('project_manager_user_id');
                $table->char('currency', 3)->default('EUR');
                $table->decimal('contract_value', 14, 2)->default(0.00);
                $table->decimal('progress_percentage', 5, 2)->default(0.00);
                $table->string('health_status', 20)->default('green'); // green, yellow, red
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('portfolio_id')->references('id')->on('project_portfolios')->nullOnDelete();
                $table->foreign('contact_id')->references('id')->on('contacts')->restrictOnDelete();
                $table->foreign('project_manager_user_id')->references('id')->on('users')->restrictOnDelete();
                $table->unique(['tenant_id', 'project_number'], 'uq_project_number');
                $table->index(['tenant_id', 'status', 'project_manager_user_id'], 'idx_projects_lookup');
            });

            Schema::table('projects', function (Blueprint $table): void {
                $table->foreign('parent_project_id')->references('id')->on('projects')->cascadeOnDelete();
            });
        }

        // 4. Project Phases
        if (! Schema::hasTable('project_phases')) {
            Schema::create('project_phases', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('project_id');
                $table->integer('sequence_order')->default(1);
                $table->string('name', 150);
                $table->string('status', 50)->default('pending'); // pending, active, completed, skipped
                $table->date('planned_start_date');
                $table->date('planned_end_date');
                $table->date('actual_start_date')->nullable();
                $table->date('actual_end_date')->nullable();
                $table->decimal('progress_percentage', 5, 2)->default(0.00);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->index(['project_id', 'sequence_order'], 'idx_project_phases');
            });
        }

        // 5. Project Milestones
        if (! Schema::hasTable('project_milestones')) {
            Schema::create('project_milestones', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('project_id');
                $table->uuid('project_phase_id')->nullable();
                $table->string('name', 150);
                $table->date('milestone_date');
                $table->decimal('billing_percentage', 5, 2)->default(0.00);
                $table->decimal('billing_amount', 14, 2)->default(0.00);
                $table->boolean('is_completed')->default(false);
                $table->timestamp('completed_at')->nullable();
                $table->boolean('is_billed')->default(false);
                $table->uuid('invoice_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('project_phase_id')->references('id')->on('project_phases')->nullOnDelete();
                $table->index(['project_id', 'milestone_date', 'is_completed'], 'idx_milestones_due');
            });
        }

        // 6. Project Tasks
        if (! Schema::hasTable('project_tasks')) {
            Schema::create('project_tasks', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('project_id');
                $table->uuid('project_phase_id')->nullable();
                $table->uuid('project_milestone_id')->nullable();
                $table->uuid('parent_task_id')->nullable();
                $table->string('task_number', 100);
                $table->string('title', 255);
                $table->text('description')->nullable();
                $table->string('status', 50)->default('backlog'); // backlog, todo, in_progress, review, completed, blocked
                $table->string('priority', 20)->default('medium'); // low, medium, high, urgent
                $table->date('planned_start_date');
                $table->date('planned_end_date');
                $table->date('actual_start_date')->nullable();
                $table->date('actual_end_date')->nullable();
                $table->decimal('estimated_hours', 8, 2)->default(0.00);
                $table->decimal('actual_hours', 8, 2)->default(0.00);
                $table->decimal('progress_percentage', 5, 2)->default(0.00);
                $table->boolean('is_milestone_driver')->default(false);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('project_phase_id')->references('id')->on('project_phases')->cascadeOnDelete();
                $table->foreign('project_milestone_id')->references('id')->on('project_milestones')->nullOnDelete();
                $table->index(['project_id', 'status', 'planned_start_date'], 'idx_project_tasks_nav');
            });

            Schema::table('project_tasks', function (Blueprint $table): void {
                $table->foreign('parent_task_id')->references('id')->on('project_tasks')->cascadeOnDelete();
            });
        }

        // 7. Project Task Dependencies
        if (! Schema::hasTable('project_task_dependencies')) {
            Schema::create('project_task_dependencies', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('predecessor_task_id');
                $table->uuid('successor_task_id');
                $table->string('dependency_type', 20)->default('finish_to_start'); // finish_to_start, start_to_start, finish_to_finish, start_to_finish
                $table->integer('lag_days')->default(0);
                $table->timestamps();

                $table->foreign('predecessor_task_id')->references('id')->on('project_tasks')->cascadeOnDelete();
                $table->foreign('successor_task_id')->references('id')->on('project_tasks')->cascadeOnDelete();
                $table->unique(['predecessor_task_id', 'successor_task_id'], 'uq_task_dependency');
            });
        }

        // 8. Project Task Assignments
        if (! Schema::hasTable('project_task_assignments')) {
            Schema::create('project_task_assignments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('project_task_id');
                $table->uuid('user_id');
                $table->string('role_description', 100)->nullable();
                $table->decimal('allocated_hours', 8, 2)->default(0.00);
                $table->timestamps();

                $table->foreign('project_task_id')->references('id')->on('project_tasks')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->index(['project_task_id', 'user_id'], 'idx_task_assignments');
            });
        }

        // 9. Project Resources
        if (! Schema::hasTable('project_resources')) {
            Schema::create('project_resources', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('resource_type', 50); // human, vehicle, equipment, subcontractor
                $table->uuid('user_id')->nullable();
                $table->uuid('contact_id')->nullable();
                $table->string('name', 150);
                $table->decimal('internal_hourly_cost', 10, 2)->default(0.00);
                $table->decimal('billing_hourly_rate', 10, 2)->default(0.00);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
                $table->index(['tenant_id', 'resource_type', 'is_active'], 'idx_proj_resources');
            });
        }

        // 10. Resource Capacity Profiles
        if (! Schema::hasTable('resource_capacity_profiles')) {
            Schema::create('resource_capacity_profiles', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('resource_id');
                $table->decimal('weekly_capacity_hours', 5, 2)->default(40.00);
                $table->decimal('maximum_overtime_hours', 5, 2)->default(10.00);
                $table->decimal('efficiency_factor', 4, 2)->default(1.00);
                $table->timestamps();

                $table->foreign('resource_id')->references('id')->on('project_resources')->cascadeOnDelete();
            });
        }

        // 11. Resource Calendars
        if (! Schema::hasTable('resource_calendars')) {
            Schema::create('resource_calendars', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('resource_id');
                $table->decimal('daily_available_hours', 4, 2)->default(8.00);
                $table->date('exception_date');
                $table->boolean('is_available')->default(false);
                $table->string('reason', 150)->nullable();
                $table->timestamps();

                $table->foreign('resource_id')->references('id')->on('project_resources')->cascadeOnDelete();
            });
        }

        // 12. Resource Allocations
        if (! Schema::hasTable('resource_allocations')) {
            Schema::create('resource_allocations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('project_id');
                $table->uuid('project_task_id')->nullable();
                $table->uuid('resource_id');
                $table->date('allocated_start_date');
                $table->date('allocated_end_date');
                $table->decimal('allocated_hours_per_day', 4, 2)->default(8.00);
                $table->decimal('total_allocated_hours', 8, 2);
                $table->timestamps();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('project_task_id')->references('id')->on('project_tasks')->cascadeOnDelete();
                $table->foreign('resource_id')->references('id')->on('project_resources')->restrictOnDelete();
                $table->index(['resource_id', 'allocated_start_date', 'allocated_end_date'], 'idx_resource_alloc');
            });
        }

        // 13. Project Budgets
        if (! Schema::hasTable('project_budgets')) {
            Schema::create('project_budgets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('project_id');
                $table->integer('current_version_number')->default(1);
                $table->string('status', 50)->default('draft'); // draft, pending_approval, approved, superseded
                $table->decimal('total_revenue_budget', 14, 2)->default(0.00);
                $table->decimal('total_cost_budget', 14, 2)->default(0.00);
                $table->decimal('labour_budget', 14, 2)->default(0.00);
                $table->decimal('material_budget', 14, 2)->default(0.00);
                $table->decimal('supplier_budget', 14, 2)->default(0.00);
                $table->decimal('fleet_budget', 14, 2)->default(0.00);
                $table->decimal('expense_budget', 14, 2)->default(0.00);
                $table->decimal('contingency_budget', 14, 2)->default(0.00);
                $table->decimal('projected_margin_amount', 14, 2)->default(0.00);
                $table->decimal('projected_margin_percentage', 5, 2)->default(0.00);
                $table->uuid('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->unique(['project_id'], 'uq_proj_budget');
            });
        }

        // 14. Project Budget Versions
        if (! Schema::hasTable('project_budget_versions')) {
            Schema::create('project_budget_versions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('project_budget_id');
                $table->integer('version_number');
                $table->text('revision_reason')->nullable();
                $table->jsonb('snapshot_payload')->default('{}');
                $table->decimal('total_revenue_budget', 14, 2);
                $table->decimal('total_cost_budget', 14, 2);
                $table->boolean('is_baseline')->default(false);
                $table->uuid('approved_by')->nullable();
                $table->timestamps();

                $table->foreign('project_budget_id')->references('id')->on('project_budgets')->cascadeOnDelete();
                $table->unique(['project_budget_id', 'version_number'], 'uq_budget_version');
            });
        }

        // 15. Project Budget Lines
        if (! Schema::hasTable('project_budget_lines')) {
            Schema::create('project_budget_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('project_budget_id');
                $table->uuid('project_phase_id')->nullable();
                $table->string('cost_category', 50); // labour, material, supplier, fleet, expense, contingency
                $table->string('description', 255);
                $table->decimal('planned_quantity', 12, 4)->default(1.0000);
                $table->decimal('planned_unit_cost', 14, 4);
                $table->decimal('planned_total_cost', 14, 2);
                $table->decimal('planned_revenue_amount', 14, 2)->default(0.00);
                $table->timestamps();

                $table->foreign('project_budget_id')->references('id')->on('project_budgets')->cascadeOnDelete();
                $table->foreign('project_phase_id')->references('id')->on('project_phases')->nullOnDelete();
                $table->index(['project_budget_id', 'cost_category'], 'idx_budget_lines_cat');
            });
        }

        // 16. Project Budget Approvals
        if (! Schema::hasTable('project_budget_approvals')) {
            Schema::create('project_budget_approvals', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('project_budget_id');
                $table->uuid('budget_version_id');
                $table->uuid('approver_user_id');
                $table->string('status', 50)->default('approved'); // approved, rejected
                $table->text('decision_notes')->nullable();
                $table->timestamps();

                $table->foreign('project_budget_id')->references('id')->on('project_budgets')->cascadeOnDelete();
                $table->foreign('budget_version_id')->references('id')->on('project_budget_versions')->cascadeOnDelete();
            });
        }

        // 17. Project Change Orders
        if (! Schema::hasTable('project_change_orders')) {
            Schema::create('project_change_orders', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('project_id');
                $table->string('order_number', 100);
                $table->string('title', 255);
                $table->text('scope_description');
                $table->decimal('cost_impact_amount', 14, 2)->default(0.00);
                $table->decimal('revenue_impact_amount', 14, 2)->default(0.00);
                $table->integer('schedule_impact_days')->default(0);
                $table->string('status', 50)->default('draft'); // draft, submitted, pending_approval, customer_approved, rejected
                $table->timestamp('approved_by_client_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->unique(['tenant_id', 'project_id', 'order_number'], 'uq_proj_change_order');
            });
        }

        // 18. Project Change Order Lines
        if (! Schema::hasTable('project_change_order_lines')) {
            Schema::create('project_change_order_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('project_change_order_id');
                $table->string('cost_category', 50);
                $table->string('description', 255);
                $table->decimal('quantity_delta', 12, 4)->default(1.0000);
                $table->decimal('unit_cost_delta', 14, 4);
                $table->decimal('total_cost_delta', 14, 2);
                $table->decimal('revenue_delta', 14, 2)->default(0.00);
                $table->timestamps();

                $table->foreign('project_change_order_id')->references('id')->on('project_change_orders')->cascadeOnDelete();
            });
        }

        // 19. Project Variation Requests
        if (! Schema::hasTable('project_variation_requests')) {
            Schema::create('project_variation_requests', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('project_id');
                $table->string('variation_code', 100);
                $table->string('title', 255);
                $table->string('requested_by', 150);
                $table->text('request_details');
                $table->string('status', 50)->default('under_review'); // under_review, approved_to_quote, rejected
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            });
        }

        // 20. Project Cost Entries (Immutable Job Costing Ledger)
        if (! Schema::hasTable('project_cost_entries')) {
            Schema::create('project_cost_entries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('project_id');
                $table->uuid('project_phase_id')->nullable();
                $table->uuid('project_task_id')->nullable();
                $table->string('cost_source_type', 255); // StockMovement, PurchaseOrderLine, ServiceReportLabour, Expense, FleetCostEntry, SubcontractorBill, Timesheet
                $table->uuid('cost_source_id');
                $table->string('cost_category', 50); // material, labour, supplier, fleet, expense, equipment
                $table->decimal('quantity', 12, 4)->default(1.0000);
                $table->string('uom', 20)->default('PCE');
                $table->decimal('unit_cost', 14, 4);
                $table->decimal('total_cost', 14, 2);
                $table->boolean('is_billable')->default(true);
                $table->boolean('is_invoiced')->default(false);
                $table->uuid('invoice_line_id')->nullable();
                $table->date('incurred_date');
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('project_phase_id')->references('id')->on('project_phases')->nullOnDelete();
                $table->foreign('project_task_id')->references('id')->on('project_tasks')->nullOnDelete();
                $table->index(['project_id', 'cost_category', 'total_cost'], 'idx_cost_entries_rollup');
                $table->index(['cost_source_type', 'cost_source_id'], 'idx_cost_source');
            });
        }

        // 21. Project Cost Allocations
        if (! Schema::hasTable('project_cost_allocations')) {
            Schema::create('project_cost_allocations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('project_cost_entry_id');
                $table->uuid('project_task_id');
                $table->decimal('allocation_percentage', 5, 2)->default(100.00);
                $table->decimal('allocated_amount', 14, 2);
                $table->timestamps();

                $table->foreign('project_cost_entry_id')->references('id')->on('project_cost_entries')->cascadeOnDelete();
                $table->foreign('project_task_id')->references('id')->on('project_tasks')->cascadeOnDelete();
            });
        }

        // 22. Project Cost Snapshots
        if (! Schema::hasTable('project_cost_snapshots')) {
            Schema::create('project_cost_snapshots', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('project_id');
                $table->date('snapshot_date');
                $table->decimal('total_material_cost', 14, 2);
                $table->decimal('total_labour_cost', 14, 2);
                $table->decimal('total_supplier_cost', 14, 2);
                $table->decimal('total_fleet_cost', 14, 2);
                $table->decimal('total_expense_cost', 14, 2);
                $table->decimal('cumulative_total_cost', 14, 2);
                $table->timestamps();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->unique(['project_id', 'snapshot_date'], 'uq_cost_snapshot');
            });
        }

        // 23. Project Financial Summaries
        if (! Schema::hasTable('project_financial_summaries')) {
            Schema::create('project_financial_summaries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('project_id');
                $table->decimal('total_contract_revenue', 14, 2)->default(0.00);
                $table->decimal('total_invoiced_revenue', 14, 2)->default(0.00);
                $table->decimal('total_recognized_revenue', 14, 2)->default(0.00);
                $table->decimal('actual_material_cost', 14, 2)->default(0.00);
                $table->decimal('actual_labour_cost', 14, 2)->default(0.00);
                $table->decimal('actual_supplier_cost', 14, 2)->default(0.00);
                $table->decimal('actual_fleet_cost', 14, 2)->default(0.00);
                $table->decimal('actual_expense_cost', 14, 2)->default(0.00);
                $table->decimal('total_actual_cost', 14, 2)->default(0.00);
                $table->decimal('committed_po_cost', 14, 2)->default(0.00);
                $table->decimal('gross_profit_amount', 14, 2)->default(0.00);
                $table->decimal('gross_profit_percentage', 5, 2)->default(0.00);
                $table->decimal('planned_value_pv', 14, 2)->default(0.00);
                $table->decimal('earned_value_ev', 14, 2)->default(0.00);
                $table->decimal('budget_at_completion_bac', 14, 2)->default(0.00);
                $table->decimal('cost_performance_index_cpi', 5, 2)->default(1.00);
                $table->decimal('schedule_performance_index_spi', 5, 2)->default(1.00);
                $table->decimal('estimate_at_completion_eac', 14, 2)->default(0.00);
                $table->decimal('estimate_to_complete_etc', 14, 2)->default(0.00);
                $table->decimal('variance_at_completion_vac', 14, 2)->default(0.00);
                $table->timestamp('last_recalculated_at')->useCurrent();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->unique(['project_id'], 'uq_proj_fin_summary');
            });
        }

        // 24. Project Profitability Snapshots
        if (! Schema::hasTable('project_profitability_snapshots')) {
            Schema::create('project_profitability_snapshots', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('project_id');
                $table->date('snapshot_date');
                $table->decimal('contract_revenue', 14, 2);
                $table->decimal('invoiced_revenue', 14, 2);
                $table->decimal('total_cost', 14, 2);
                $table->decimal('net_profit_amount', 14, 2);
                $table->decimal('profit_margin_pct', 5, 2);
                $table->decimal('cpi', 5, 2);
                $table->decimal('spi', 5, 2);
                $table->decimal('progress_percentage', 5, 2);
                $table->timestamps();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->unique(['project_id', 'snapshot_date'], 'uq_proj_snapshot');
            });
        }

        // 25. Project Revenue Recognition (IFRS 15)
        if (! Schema::hasTable('project_revenue_recognition')) {
            Schema::create('project_revenue_recognition', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('project_id');
                $table->date('accounting_period_date');
                $table->decimal('cumulative_actual_cost', 14, 2);
                $table->decimal('estimated_total_cost_eac', 14, 2);
                $table->decimal('cost_completion_percentage', 5, 2);
                $table->decimal('cumulative_recognized_revenue', 14, 2);
                $table->decimal('period_revenue_adjustment', 14, 2);
                $table->boolean('is_posted_to_gl')->default(false);
                $table->timestamp('posted_at')->nullable();
                $table->timestamps();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->unique(['project_id', 'accounting_period_date'], 'uq_rev_rec_period');
            });
        }

        // 26. Project Billing Events
        if (! Schema::hasTable('project_billing_events')) {
            Schema::create('project_billing_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('project_id');
                $table->string('event_type', 50); // milestone, percentage_progress, time_and_materials
                $table->uuid('source_reference_id')->nullable();
                $table->decimal('billing_amount', 14, 2);
                $table->string('status', 50)->default('pending'); // pending, invoiced, cancelled
                $table->uuid('invoice_id')->nullable();
                $table->timestamps();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            });
        }

        // 27. Project Invoice Links
        if (! Schema::hasTable('project_invoice_links')) {
            Schema::create('project_invoice_links', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('project_id');
                $table->uuid('invoice_id');
                $table->decimal('allocated_revenue', 14, 2);
                $table->timestamps();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->index(['project_id', 'invoice_id'], 'idx_proj_inv_link');
            });
        }

        // 28. Project Audit Entries
        if (! Schema::hasTable('project_audit_entries')) {
            Schema::create('project_audit_entries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('project_id');
                $table->uuid('actor_user_id');
                $table->string('event_type', 100);
                $table->text('description');
                $table->jsonb('before_state')->default('{}');
                $table->jsonb('after_state')->default('{}');
                $table->timestamps();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->index(['project_id', 'created_at'], 'idx_proj_audit_seq');
            });
        }

        // 29. Project Timesheets (Time Tracking)
        if (! Schema::hasTable('project_timesheets')) {
            Schema::create('project_timesheets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('project_id');
                $table->uuid('project_task_id')->nullable();
                $table->uuid('user_id');
                $table->date('work_date');
                $table->decimal('hours_worked', 6, 2);
                $table->decimal('overtime_hours', 6, 2)->default(0.00);
                $table->boolean('is_billable')->default(true);
                $table->decimal('cost_rate', 10, 2);
                $table->decimal('charge_rate', 10, 2);
                $table->decimal('total_cost', 14, 2);
                $table->decimal('total_charge', 14, 2);
                $table->string('status', 50)->default('submitted'); // draft, submitted, approved, rejected, invoiced
                $table->uuid('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->text('work_summary')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('project_task_id')->references('id')->on('project_tasks')->nullOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->index(['project_id', 'user_id', 'work_date'], 'idx_timesheet_search');
            });
        }

        // 30. Project Risks
        if (! Schema::hasTable('project_risks')) {
            Schema::create('project_risks', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('project_id');
                $table->string('code', 50);
                $table->string('title', 255);
                $table->string('category', 50)->default('operational'); // operational, financial, technical, schedule, external
                $table->string('probability', 20)->default('medium'); // low, medium, high
                $table->string('impact', 20)->default('medium'); // low, medium, high, critical
                $table->string('severity', 20)->default('medium'); // low, medium, high, critical
                $table->decimal('potential_cost_impact', 14, 2)->default(0.00);
                $table->text('mitigation_plan')->nullable();
                $table->string('status', 50)->default('identified'); // identified, monitoring, mitigated, materialized, closed
                $table->uuid('owner_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('owner_user_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        // 31. Project Issues
        if (! Schema::hasTable('project_issues')) {
            Schema::create('project_issues', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('project_id');
                $table->string('code', 50);
                $table->string('title', 255);
                $table->string('priority', 20)->default('medium');
                $table->string('severity', 20)->default('medium');
                $table->string('status', 50)->default('open'); // open, in_progress, resolved, closed
                $table->text('description');
                $table->text('resolution')->nullable();
                $table->uuid('assigned_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('assigned_user_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        // 32. Project Notes
        if (! Schema::hasTable('project_notes')) {
            Schema::create('project_notes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('project_id');
                $table->uuid('author_user_id');
                $table->string('title', 255)->nullable();
                $table->text('content');
                $table->boolean('is_pinned')->default(false);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('author_user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        // 33. Project Documents
        if (! Schema::hasTable('project_documents')) {
            Schema::create('project_documents', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('project_id');
                $table->string('title', 255);
                $table->string('document_category', 50)->default('general'); // contract, specification, drawing, report, general
                $table->string('file_path', 500);
                $table->string('file_name', 255);
                $table->integer('file_size_bytes')->default(0);
                $table->string('mime_type', 100)->nullable();
                $table->integer('version')->default(1);
                $table->uuid('uploaded_by');
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('uploaded_by')->references('id')->on('users')->restrictOnDelete();
            });
        }

        // 34. Project Status Histories
        if (! Schema::hasTable('project_status_histories')) {
            Schema::create('project_status_histories', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('project_id');
                $table->string('from_status', 50);
                $table->string('to_status', 50);
                $table->text('change_reason')->nullable();
                $table->uuid('changed_by');
                $table->timestamps();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('changed_by')->references('id')->on('users')->restrictOnDelete();
                $table->index(['project_id', 'created_at'], 'idx_proj_status_hist');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_status_histories');
        Schema::dropIfExists('project_documents');
        Schema::dropIfExists('project_notes');
        Schema::dropIfExists('project_issues');
        Schema::dropIfExists('project_risks');
        Schema::dropIfExists('project_timesheets');
        Schema::dropIfExists('project_audit_entries');
        Schema::dropIfExists('project_invoice_links');
        Schema::dropIfExists('project_billing_events');
        Schema::dropIfExists('project_revenue_recognition');
        Schema::dropIfExists('project_profitability_snapshots');
        Schema::dropIfExists('project_financial_summaries');
        Schema::dropIfExists('project_cost_snapshots');
        Schema::dropIfExists('project_cost_allocations');
        Schema::dropIfExists('project_cost_entries');
        Schema::dropIfExists('project_variation_requests');
        Schema::dropIfExists('project_change_order_lines');
        Schema::dropIfExists('project_change_orders');
        Schema::dropIfExists('project_budget_approvals');
        Schema::dropIfExists('project_budget_lines');
        Schema::dropIfExists('project_budget_versions');
        Schema::dropIfExists('project_budgets');
        Schema::dropIfExists('resource_allocations');
        Schema::dropIfExists('resource_calendars');
        Schema::dropIfExists('resource_capacity_profiles');
        Schema::dropIfExists('project_resources');
        Schema::dropIfExists('project_task_assignments');
        Schema::dropIfExists('project_task_dependencies');
        Schema::dropIfExists('project_tasks');
        Schema::dropIfExists('project_milestones');
        Schema::dropIfExists('project_phases');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('project_templates');
        Schema::dropIfExists('project_portfolios');
    }
};
