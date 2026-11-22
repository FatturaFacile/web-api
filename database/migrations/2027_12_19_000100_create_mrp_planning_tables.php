<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MRP I & II (Mod 063): the planning runs, the action proposals with their pegging, the finite-capacity schedule blocks
 * and the daily work-centre capacity buckets, plus the planning parameters the upstream modules do not carry (lot-sizing
 * rule, supplier country and customs days per item, supplier shutdown periods, alternative work centres).
 * Cross-module references (item, work centre, supplier, BOM, orders) are plain uuid columns. JSON columns are jsonb on
 * PostgreSQL and text on SQLite. Every table carries a nullable tenant_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mrp_run_executions', function (Blueprint $table): void {
            $this->base($table);
            $table->string('run_code', 48);
            $table->string('status', 16)->default('Pending');
            $table->string('run_mode', 24);
            $table->unsignedSmallInteger('planning_horizon_days');
            $table->string('capacity_scheduling_mode', 32);
            $table->string('scheduling_direction', 32);
            $table->boolean('include_cross_border_customs_lead_time')->default(false);
            $table->boolean('apply_supplier_shutdown_calendars')->default(false);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->unsignedBigInteger('execution_duration_ms')->nullable();
            $table->jsonb('summary_metrics')->nullable();
            $table->text('error_message')->nullable();
            $this->audit($table);

            $table->unique(['tenant_id', 'run_code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('mrp_action_proposals', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('mrp_run_id');
            $table->uuid('mfg_item_id');
            $table->uuid('product_id')->nullable();
            $table->uuid('bom_version_id')->nullable();
            $table->uuid('work_center_id')->nullable();
            $table->unsignedSmallInteger('low_level_code')->default(0);
            $table->string('proposal_type', 40);
            $table->string('status', 16)->default('Proposed');
            $table->decimal('gross_requirement_qty', 18, 4)->default(0);
            $table->decimal('projected_on_hand_qty', 18, 4)->default(0);
            $table->decimal('net_requirement_qty', 18, 4)->default(0);
            $table->decimal('required_qty', 18, 4)->default(0);
            $table->decimal('suggested_order_qty', 18, 4)->default(0);
            $table->date('need_by_date');
            $table->date('release_order_by_date');
            $table->dateTime('planned_start_at')->nullable();
            $table->dateTime('planned_end_at')->nullable();
            $table->jsonb('pegged_demand_source_ids')->nullable();
            $table->jsonb('exception_messages')->nullable();
            $table->string('supply_reference', 64)->nullable();
            $table->string('firm_reference_type', 40)->nullable();
            $table->uuid('firm_reference_id')->nullable();
            $table->dateTime('firmed_at')->nullable();
            $table->dateTime('released_at')->nullable();
            $this->audit($table);

            $table->foreign('mrp_run_id')->references('id')->on('mrp_run_executions')->cascadeOnDelete();
            $table->index(['tenant_id', 'mrp_run_id', 'status']);
            $table->index(['tenant_id', 'mrp_run_id', 'proposal_type']);
            $table->index(['tenant_id', 'mfg_item_id']);
            $table->index(['tenant_id', 'need_by_date']);
        });

        Schema::create('mrp_schedule_blocks', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('mrp_run_id');
            $table->uuid('proposal_id');
            $table->uuid('work_center_id');
            $table->uuid('original_work_center_id')->nullable();
            $table->unsignedSmallInteger('operation_sequence')->default(10);
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('minutes')->default(0);
            $table->jsonb('segments')->nullable();
            $table->boolean('is_manual')->default(false);
            $this->audit($table);

            $table->foreign('mrp_run_id')->references('id')->on('mrp_run_executions')->cascadeOnDelete();
            $table->foreign('proposal_id')->references('id')->on('mrp_action_proposals')->cascadeOnDelete();
            $table->index(['tenant_id', 'mrp_run_id', 'work_center_id']);
            $table->index(['tenant_id', 'proposal_id']);
        });

        Schema::create('mrp_work_center_capacity_buckets', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('mrp_run_id');
            $table->uuid('work_center_id');
            $table->date('bucket_date');
            $table->string('shift_code', 24)->nullable();
            $table->unsignedInteger('available_minutes')->default(0);
            $table->unsignedInteger('loaded_minutes')->default(0);
            $table->decimal('load_percentage', 8, 2)->default(0);
            $table->boolean('is_overloaded')->default(false);
            $table->jsonb('assigned_proposal_ids')->nullable();
            $this->audit($table);

            $table->foreign('mrp_run_id')->references('id')->on('mrp_run_executions')->cascadeOnDelete();
            $table->index(['tenant_id', 'mrp_run_id', 'work_center_id', 'bucket_date'], 'mrp_buckets_run_wc_date_index');
        });

        Schema::create('mrp_item_parameters', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('mfg_item_id');
            $table->string('lot_sizing_rule', 32)->default('LotForLot');
            $table->decimal('eoq_quantity', 18, 4)->default(0);
            $table->unsignedSmallInteger('fixed_days_of_supply')->default(0);
            $table->uuid('supplier_id')->nullable();
            $table->string('supplier_country', 2)->nullable();
            $table->unsignedSmallInteger('customs_clearance_days')->nullable();
            $this->audit($table);

            $table->unique(['tenant_id', 'mfg_item_id']);
        });

        Schema::create('mrp_shutdown_periods', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('supplier_id')->nullable();
            $table->string('label', 120);
            $table->string('country', 2)->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $this->audit($table);

            $table->index(['tenant_id', 'starts_on', 'ends_on']);
            $table->index(['tenant_id', 'supplier_id']);
        });

        Schema::create('mrp_work_center_alternatives', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('work_center_id');
            $table->uuid('alternative_work_center_id');
            $table->unsignedSmallInteger('priority')->default(1);
            $this->audit($table);

            $table->unique(['tenant_id', 'work_center_id', 'alternative_work_center_id'], 'mrp_wc_alternatives_unique');
        });
    }

    public function down(): void
    {
        foreach (['mrp_work_center_alternatives', 'mrp_shutdown_periods', 'mrp_item_parameters', 'mrp_work_center_capacity_buckets', 'mrp_schedule_blocks', 'mrp_action_proposals', 'mrp_run_executions'] as $name) {
            Schema::dropIfExists($name);
        }
    }

    private function base(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
    }

    private function audit(Blueprint $table): void
    {
        $table->timestamps();
        $table->softDeletes();
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
    }
};
