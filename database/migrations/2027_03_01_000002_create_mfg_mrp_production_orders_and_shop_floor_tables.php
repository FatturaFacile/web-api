<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 10. MRP Runs
        if (! Schema::hasTable('mfg_mrp_runs')) {
            Schema::create('mfg_mrp_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('run_number', 50)->index();
                $table->string('run_type', 30)->default('regenerative')->index();
                $table->string('status', 30)->default('running')->index();
                $table->date('horizon_start_date');
                $table->date('horizon_end_date');
                $table->integer('total_items_processed')->default(0);
                $table->integer('total_planned_orders')->default(0);
                $table->integer('total_shortages')->default(0);
                $table->uuid('executed_by')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->text('error_message')->nullable();
                $table->json('parameters')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'run_number']);
            });
        }

        // 11. MRP Planned Orders
        if (! Schema::hasTable('mfg_mrp_planned_orders')) {
            Schema::create('mfg_mrp_planned_orders', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('planned_order_number', 50)->index();
                $table->uuid('mrp_run_id')->index();
                $table->uuid('mfg_item_id')->index();
                $table->string('order_type', 20)->default('make')->index();
                $table->decimal('quantity', 15, 4);
                $table->string('uom', 20)->default('PCS');
                $table->date('start_date');
                $table->date('due_date');
                $table->string('status', 30)->default('planned')->index();
                $table->uuid('bom_id')->nullable()->index();
                $table->uuid('routing_id')->nullable()->index();
                $table->uuid('converted_order_id')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('mrp_run_id')->references('id')->on('mfg_mrp_runs')->cascadeOnDelete();
                $table->unique(['tenant_id', 'planned_order_number']);
            });
        }

        // 12. MRP Shortages
        if (! Schema::hasTable('mfg_mrp_shortages')) {
            Schema::create('mfg_mrp_shortages', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('mrp_run_id')->index();
                $table->uuid('mfg_item_id')->index();
                $table->date('required_date');
                $table->decimal('gross_requirement', 15, 4)->default(0.0000);
                $table->decimal('available_inventory', 15, 4)->default(0.0000);
                $table->decimal('scheduled_receipts', 15, 4)->default(0.0000);
                $table->decimal('safety_stock', 15, 4)->default(0.0000);
                $table->decimal('net_shortage', 15, 4)->default(0.0000);
                $table->string('recommended_action', 50)->default('purchase_order');
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('mrp_run_id')->references('id')->on('mfg_mrp_runs')->cascadeOnDelete();
            });
        }

        // 13. MRP Pegging Records
        if (! Schema::hasTable('mfg_mrp_peggings')) {
            Schema::create('mfg_mrp_peggings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('planned_order_id')->index();
                $table->string('demand_type', 50)->index();
                $table->uuid('demand_reference_id')->nullable()->index();
                $table->decimal('pegged_quantity', 15, 4);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('planned_order_id')->references('id')->on('mfg_mrp_planned_orders')->cascadeOnDelete();
            });
        }

        // 14. Production Orders
        if (! Schema::hasTable('mfg_production_orders')) {
            Schema::create('mfg_production_orders', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('order_number', 50)->index();
                $table->uuid('mfg_item_id')->index();
                $table->uuid('bom_id')->index();
                $table->uuid('routing_id')->nullable()->index();
                $table->uuid('project_id')->nullable()->index();
                $table->string('status', 30)->default('planned')->index();
                $table->decimal('target_quantity', 15, 4);
                $table->decimal('completed_quantity', 15, 4)->default(0.0000);
                $table->decimal('scrapped_quantity', 15, 4)->default(0.0000);
                $table->string('uom', 20)->default('PCS');
                $table->date('planned_start_date');
                $table->date('planned_end_date');
                $table->timestamp('actual_start_date')->nullable();
                $table->timestamp('actual_end_date')->nullable();
                $table->decimal('standard_cost_total', 15, 4)->default(0.0000);
                $table->decimal('actual_cost_total', 15, 4)->default(0.0000);
                $table->decimal('variance_total', 15, 4)->default(0.0000);
                $table->integer('lock_version')->default(1);
                $table->boolean('is_locked')->default(false);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'order_number']);
            });
        }

        // 15. Production Order Component Lines
        if (! Schema::hasTable('mfg_production_order_lines')) {
            Schema::create('mfg_production_order_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('production_order_id')->index();
                $table->uuid('mfg_item_id')->index();
                $table->uuid('operation_id')->nullable()->index();
                $table->decimal('required_quantity', 15, 4);
                $table->decimal('allocated_quantity', 15, 4)->default(0.0000);
                $table->decimal('consumed_quantity', 15, 4)->default(0.0000);
                $table->decimal('scrapped_quantity', 15, 4)->default(0.0000);
                $table->string('uom', 20)->default('PCS');
                $table->decimal('unit_cost', 15, 4)->default(0.0000);
                $table->decimal('total_cost', 15, 4)->default(0.0000);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('production_order_id')->references('id')->on('mfg_production_orders')->cascadeOnDelete();
            });
        }

        // 16. Production Order Operations
        if (! Schema::hasTable('mfg_production_order_operations')) {
            Schema::create('mfg_production_order_operations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('production_order_id')->index();
                $table->uuid('operation_id')->nullable()->index();
                $table->integer('sequence')->default(10);
                $table->uuid('work_center_id')->index();
                $table->uuid('machine_center_id')->nullable()->index();
                $table->string('status', 30)->default('pending')->index();
                $table->decimal('planned_setup_minutes', 8, 2)->default(0.00);
                $table->decimal('actual_setup_minutes', 8, 2)->default(0.00);
                $table->decimal('planned_run_minutes', 8, 2)->default(0.00);
                $table->decimal('actual_run_minutes', 8, 2)->default(0.00);
                $table->decimal('completed_quantity', 15, 4)->default(0.0000);
                $table->decimal('scrapped_quantity', 15, 4)->default(0.0000);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('production_order_id')->references('id')->on('mfg_production_orders')->cascadeOnDelete();
            });
        }

        // 17. Shop Floor Executions (Job Cards)
        if (! Schema::hasTable('mfg_shop_floor_executions')) {
            Schema::create('mfg_shop_floor_executions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('execution_number', 50)->index();
                $table->uuid('production_order_id')->index();
                $table->uuid('production_order_operation_id')->index();
                $table->uuid('work_center_id')->index();
                $table->uuid('operator_id')->nullable()->index();
                $table->uuid('machine_id')->nullable()->index();
                $table->string('status', 30)->default('active')->index();
                $table->decimal('good_units', 15, 4)->default(0.0000);
                $table->decimal('scrap_units', 15, 4)->default(0.0000);
                $table->timestamp('started_at');
                $table->timestamp('completed_at')->nullable();
                $table->text('downtime_reason')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('production_order_id')->references('id')->on('mfg_production_orders')->cascadeOnDelete();
                $table->unique(['tenant_id', 'execution_number']);
            });
        }

        // 18. Labor Time Logs
        if (! Schema::hasTable('mfg_labor_time_logs')) {
            Schema::create('mfg_labor_time_logs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('execution_id')->index();
                $table->uuid('operator_id')->index();
                $table->timestamp('start_time');
                $table->timestamp('end_time')->nullable();
                $table->decimal('duration_minutes', 8, 2)->default(0.00);
                $table->decimal('hourly_rate', 15, 4)->default(0.0000);
                $table->decimal('labor_cost', 15, 4)->default(0.0000);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('execution_id')->references('id')->on('mfg_shop_floor_executions')->cascadeOnDelete();
            });
        }

        // 19. Machine Logs
        if (! Schema::hasTable('mfg_machine_logs')) {
            Schema::create('mfg_machine_logs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('execution_id')->index();
                $table->uuid('machine_id')->index();
                $table->timestamp('start_time');
                $table->timestamp('end_time')->nullable();
                $table->decimal('run_minutes', 8, 2)->default(0.00);
                $table->decimal('downtime_minutes', 8, 2)->default(0.00);
                $table->decimal('hourly_rate', 15, 4)->default(0.0000);
                $table->decimal('machine_cost', 15, 4)->default(0.0000);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('execution_id')->references('id')->on('mfg_shop_floor_executions')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mfg_machine_logs');
        Schema::dropIfExists('mfg_labor_time_logs');
        Schema::dropIfExists('mfg_shop_floor_executions');
        Schema::dropIfExists('mfg_production_order_operations');
        Schema::dropIfExists('mfg_production_order_lines');
        Schema::dropIfExists('mfg_production_orders');
        Schema::dropIfExists('mfg_mrp_peggings');
        Schema::dropIfExists('mfg_mrp_shortages');
        Schema::dropIfExists('mfg_mrp_planned_orders');
        Schema::dropIfExists('mfg_mrp_runs');
    }
};
