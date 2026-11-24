<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sales and operations planning: scenarios (one baseline and what-if copies), demand and supply plan lines per item
     * and month, capacity resources with their per-month availability, the load an item puts on a resource, the S&OP
     * approval trail and the published plan versions. New tables only; the cycle rules (a locked plan is immutable, one
     * baseline per company) are enforced by the application, not by the engine.
     */
    public function up(): void
    {
        if (! Schema::hasTable('planning_scenarios')) {
            Schema::create('planning_scenarios', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('name', 160);
                $table->text('description')->nullable();
                $table->string('scenario_type', 20)->default('baseline');
                $table->uuid('base_scenario_id')->nullable()->index();
                $table->string('status', 20)->default('draft');
                $table->date('horizon_start');
                $table->date('horizon_end');
                $table->uuid('submitted_by')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->uuid('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->uuid('locked_by')->nullable();
                $table->timestamp('locked_at')->nullable();
                $table->unsignedInteger('published_version')->default(0);
                $table->timestamp('published_at')->nullable();
                $table->uuid('published_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'scenario_type']);
            });
        }

        if (! Schema::hasTable('planning_plan_lines')) {
            Schema::create('planning_plan_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('scenario_id')->constrained('planning_scenarios')->cascadeOnDelete();
                $table->string('line_type', 10);
                $table->uuid('item_id')->nullable()->index();
                $table->string('item_code', 60);
                $table->string('item_name', 160)->nullable();
                $table->date('period');
                $table->decimal('quantity', 15, 3)->default(0);
                $table->string('source', 20)->default('manual');
                $table->string('notes', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['scenario_id', 'line_type', 'item_code', 'period'], 'planning_lines_lookup');
                $table->index(['tenant_id', 'period']);
            });
        }

        if (! Schema::hasTable('planning_resources')) {
            Schema::create('planning_resources', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 40);
                $table->string('name', 160);
                $table->uuid('work_center_id')->nullable()->index();
                $table->string('unit', 20)->default('hours');
                $table->decimal('default_capacity', 15, 3)->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('planning_item_resource_rates')) {
            Schema::create('planning_item_resource_rates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('resource_id')->constrained('planning_resources')->cascadeOnDelete();
                $table->string('item_code', 60);
                $table->decimal('load_per_unit', 15, 5);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'item_code']);
            });
        }

        if (! Schema::hasTable('planning_capacity_constraints')) {
            Schema::create('planning_capacity_constraints', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('scenario_id')->constrained('planning_scenarios')->cascadeOnDelete();
                $table->foreignUuid('resource_id')->constrained('planning_resources')->cascadeOnDelete();
                $table->date('period');
                $table->decimal('available_capacity', 15, 3);
                $table->string('notes', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['scenario_id', 'resource_id', 'period'], 'planning_constraints_lookup');
            });
        }

        if (! Schema::hasTable('planning_approvals')) {
            Schema::create('planning_approvals', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('scenario_id')->constrained('planning_scenarios')->cascadeOnDelete();
                $table->string('action', 20);
                $table->string('from_status', 20);
                $table->string('to_status', 20);
                $table->uuid('user_id')->nullable();
                $table->string('comment', 1000)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['scenario_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('planning_publications')) {
            Schema::create('planning_publications', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('scenario_id')->constrained('planning_scenarios')->cascadeOnDelete();
                $table->unsignedInteger('version');
                $table->boolean('is_current')->default(true);
                $table->json('snapshot');
                $table->uuid('published_by')->nullable();
                $table->timestamp('published_at');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'is_current']);
            });
        }
    }

    public function down(): void
    {
        foreach (['planning_publications', 'planning_approvals', 'planning_capacity_constraints', 'planning_item_resource_rates', 'planning_resources', 'planning_plan_lines', 'planning_scenarios'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
