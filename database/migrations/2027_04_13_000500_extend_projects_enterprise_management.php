<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects (commesse): schedule baselines, timesheet approval, rate cards, expense approval, billing links, revenue
 * recognition and EVM snapshots. Only additive changes, every column nullable or with a default, so existing rows stay
 * valid and the migration can run on production with --force.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            if (! Schema::hasColumn('projects', 'revenue_recognition_method')) {
                $table->string('revenue_recognition_method', 40)->nullable();
            }
            if (! Schema::hasColumn('projects', 'baseline_start_date')) {
                $table->date('baseline_start_date')->nullable();
                $table->date('baseline_end_date')->nullable();
                $table->timestamp('baselined_at')->nullable();
            }
            if (! Schema::hasColumn('projects', 'closed_at')) {
                $table->timestamp('closed_at')->nullable();
                $table->uuid('closed_by')->nullable();
            }
        });

        Schema::table('project_tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('project_tasks', 'baseline_start_date')) {
                $table->date('baseline_start_date')->nullable();
                $table->date('baseline_end_date')->nullable();
            }
            if (! Schema::hasColumn('project_tasks', 'wbs_code')) {
                $table->string('wbs_code', 50)->nullable();
                $table->integer('sort_order')->default(0);
            }
        });

        Schema::table('project_timesheets', function (Blueprint $table): void {
            if (! Schema::hasColumn('project_timesheets', 'role')) {
                $table->string('role', 100)->nullable();
            }
            if (! Schema::hasColumn('project_timesheets', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable();
                $table->uuid('rejected_by')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->text('rejection_reason')->nullable();
            }
            if (! Schema::hasColumn('project_timesheets', 'project_cost_entry_id')) {
                $table->uuid('project_cost_entry_id')->nullable();
                $table->uuid('invoice_id')->nullable()->index();
            }
            if (! Schema::hasIndex('project_timesheets', 'idx_timesheet_user_day')) {
                $table->index(['tenant_id', 'user_id', 'work_date'], 'idx_timesheet_user_day');
            }
        });

        Schema::table('project_cost_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('project_cost_entries', 'approval_status')) {
                $table->string('approval_status', 30)->default('approved');
                $table->uuid('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->text('rejection_reason')->nullable();
            }
            if (! Schema::hasColumn('project_cost_entries', 'description')) {
                $table->string('description', 255)->nullable();
            }
            if (! Schema::hasColumn('project_cost_entries', 'invoice_id')) {
                $table->uuid('invoice_id')->nullable()->index();
            }
        });

        Schema::table('project_change_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('project_change_orders', 'approved_by')) {
                $table->uuid('approved_by')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->text('rejection_reason')->nullable();
            }
        });

        Schema::table('project_milestones', function (Blueprint $table): void {
            if (! Schema::hasColumn('project_milestones', 'billed_at')) {
                $table->timestamp('billed_at')->nullable();
            }
        });

        Schema::table('project_revenue_recognition', function (Blueprint $table): void {
            if (! Schema::hasColumn('project_revenue_recognition', 'recognition_method')) {
                $table->string('recognition_method', 40)->nullable();
                $table->decimal('contract_value', 14, 2)->default(0);
                $table->decimal('cumulative_billed_revenue', 14, 2)->default(0);
                $table->decimal('contract_asset_balance', 14, 2)->default(0);
                $table->decimal('period_contract_asset_adjustment', 14, 2)->default(0);
                $table->decimal('cumulative_cost_of_revenue', 14, 2)->default(0);
                $table->decimal('period_cost_of_revenue', 14, 2)->default(0);
                $table->uuid('recognized_by')->nullable();
            }
        });

        if (! Schema::hasTable('project_rate_cards')) {
            Schema::create('project_rate_cards', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable();
                $table->uuid('project_id')->nullable();
                $table->uuid('user_id')->nullable();
                $table->string('role', 100)->nullable();
                $table->decimal('cost_rate', 10, 2);
                $table->decimal('bill_rate', 10, 2);
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->index(['tenant_id', 'project_id', 'user_id'], 'idx_rate_cards_lookup');
            });
        }

        if (! Schema::hasTable('project_evm_snapshots')) {
            Schema::create('project_evm_snapshots', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable();
                $table->uuid('project_id');
                $table->date('snapshot_date');
                $table->decimal('percent_complete', 7, 2)->default(0);
                $table->decimal('planned_percent_complete', 7, 2)->default(0);
                $table->decimal('budget_at_completion', 14, 2)->default(0);
                $table->decimal('planned_value', 14, 2)->default(0);
                $table->decimal('earned_value', 14, 2)->default(0);
                $table->decimal('actual_cost', 14, 2)->default(0);
                $table->decimal('cost_variance', 14, 2)->default(0);
                $table->decimal('schedule_variance', 14, 2)->default(0);
                $table->decimal('cost_performance_index', 8, 4)->nullable();
                $table->decimal('schedule_performance_index', 8, 4)->nullable();
                $table->decimal('estimate_at_completion', 14, 2)->default(0);
                $table->decimal('estimate_to_complete', 14, 2)->default(0);
                $table->decimal('variance_at_completion', 14, 2)->default(0);
                $table->string('health_status', 20)->default('green');
                $table->uuid('calculated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->unique(['project_id', 'snapshot_date'], 'uq_proj_evm_snapshot');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_evm_snapshots');
        Schema::dropIfExists('project_rate_cards');
    }
};
