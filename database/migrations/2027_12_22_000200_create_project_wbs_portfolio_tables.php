<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 078: portfolio, multilevel WBS, CPM dependencies and schedule baselines of a project, plus the CUP/CIG codes a
 * public-sector project passes on to its purchase orders and electronic invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            foreach (['cup_code' => 15, 'cig_code' => 10] as $column => $length) {
                if (! Schema::hasColumn('projects', $column)) {
                    $table->string($column, $length)->nullable();
                }
            }

            // Which FatturaPA block carries the CUP/CIG: contract (DatiContratto) or convention (DatiConvenzione).
            if (! Schema::hasColumn('projects', 'sdi_reference_block')) {
                $table->string('sdi_reference_block', 20)->default('contract');
            }

            foreach (['planned_hours', 'actual_hours', 'planned_cost', 'actual_cost', 'planned_revenue'] as $column) {
                if (! Schema::hasColumn('projects', $column)) {
                    $table->decimal($column, 16, 2)->default(0);
                }
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_orders', 'cup_code')) {
                $table->string('cup_code', 15)->nullable();
            }

            if (! Schema::hasColumn('purchase_orders', 'cig_code')) {
                $table->string('cig_code', 10)->nullable();
            }
        });

        if (! Schema::hasTable('project_wbs_nodes')) {
            Schema::create('project_wbs_nodes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('project_id');
                $table->uuid('parent_node_id')->nullable();
                $table->string('wbs_code', 100)->default('');
                $table->string('title', 255);
                $table->string('node_type', 30)->default('work_package'); // phase, deliverable, work_package, milestone
                $table->string('status', 30)->default('todo'); // kanban column: backlog, todo, in_progress, review, done, blocked
                $table->string('required_role', 100)->nullable();
                $table->decimal('planned_hours', 14, 2)->default(0);
                $table->decimal('actual_hours', 14, 2)->default(0);
                $table->decimal('planned_cost', 16, 2)->default(0);
                $table->decimal('actual_cost', 16, 2)->default(0);
                $table->decimal('planned_revenue', 16, 2)->default(0);
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->decimal('percent_complete', 5, 2)->default(0);
                $table->unsignedInteger('sort_order')->default(0);
                $table->date('early_start')->nullable();
                $table->date('early_finish')->nullable();
                $table->date('late_start')->nullable();
                $table->date('late_finish')->nullable();
                $table->integer('total_float_days')->nullable();
                $table->integer('free_float_days')->nullable();
                $table->boolean('is_critical')->default(false);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('parent_node_id')->references('id')->on('project_wbs_nodes')->cascadeOnDelete();
                $table->index(['project_id', 'parent_node_id', 'sort_order'], 'idx_wbs_nodes_tree');
                $table->index(['project_id', 'status'], 'idx_wbs_nodes_status');
            });
        }

        if (! Schema::hasTable('project_wbs_dependencies')) {
            Schema::create('project_wbs_dependencies', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('project_id');
                $table->uuid('predecessor_node_id');
                $table->uuid('successor_node_id');
                $table->string('dependency_type', 2)->default('FS'); // FS, SS, FF, SF
                $table->integer('lag_days')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('predecessor_node_id')->references('id')->on('project_wbs_nodes')->cascadeOnDelete();
                $table->foreign('successor_node_id')->references('id')->on('project_wbs_nodes')->cascadeOnDelete();
                $table->unique(['predecessor_node_id', 'successor_node_id'], 'uq_wbs_dependency_pair');
            });
        }

        if (! Schema::hasTable('project_wbs_baselines')) {
            Schema::create('project_wbs_baselines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('project_id');
                $table->unsignedInteger('baseline_number');
                $table->string('name', 150);
                $table->timestamp('captured_at');
                $table->uuid('captured_by')->nullable();
                $table->json('snapshot_json');
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->unique(['project_id', 'baseline_number'], 'uq_wbs_baseline_number');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_wbs_baselines');
        Schema::dropIfExists('project_wbs_dependencies');
        Schema::dropIfExists('project_wbs_nodes');

        Schema::table('purchase_orders', function (Blueprint $table): void {
            foreach (['cup_code', 'cig_code'] as $column) {
                if (Schema::hasColumn('purchase_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('projects', function (Blueprint $table): void {
            foreach (['cup_code', 'cig_code', 'sdi_reference_block', 'planned_hours', 'actual_hours', 'planned_cost', 'actual_cost', 'planned_revenue'] as $column) {
                if (Schema::hasColumn('projects', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
