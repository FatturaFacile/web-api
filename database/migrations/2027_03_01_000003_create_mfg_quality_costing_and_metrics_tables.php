<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 20. Quality Inspection Plans
        if (! Schema::hasTable('mfg_quality_plans')) {
            Schema::create('mfg_quality_plans', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('plan_code', 50)->index();
                $table->string('name', 200);
                $table->string('inspection_type', 30)->default('in_process')->index();
                $table->uuid('mfg_item_id')->nullable()->index();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'plan_code']);
            });
        }

        // 21. Quality Characteristics
        if (! Schema::hasTable('mfg_quality_characteristics')) {
            Schema::create('mfg_quality_characteristics', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('quality_plan_id')->index();
                $table->string('characteristic_name', 100);
                $table->decimal('target_value', 15, 4)->nullable();
                $table->decimal('min_tolerance', 15, 4)->nullable();
                $table->decimal('max_tolerance', 15, 4)->nullable();
                $table->string('uom', 20)->default('MM');
                $table->string('inspection_method', 100)->nullable();
                $table->boolean('is_critical')->default(false);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('quality_plan_id')->references('id')->on('mfg_quality_plans')->cascadeOnDelete();
            });
        }

        // 22. Quality Inspections
        if (! Schema::hasTable('mfg_quality_inspections')) {
            Schema::create('mfg_quality_inspections', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('inspection_number', 50)->index();
                $table->uuid('quality_plan_id')->index();
                $table->uuid('production_order_id')->nullable()->index();
                $table->uuid('operation_id')->nullable()->index();
                $table->uuid('inspector_id')->nullable()->index();
                $table->integer('sample_size')->default(1);
                $table->integer('defect_count')->default(0);
                $table->string('status', 30)->default('pending')->index();
                $table->timestamp('inspected_at')->nullable();
                $table->text('notes')->nullable();
                $table->json('characteristic_results')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('quality_plan_id')->references('id')->on('mfg_quality_plans')->cascadeOnDelete();
                $table->unique(['tenant_id', 'inspection_number']);
            });
        }

        // 23. Quality Non-Conformances
        if (! Schema::hasTable('mfg_quality_non_conformances')) {
            Schema::create('mfg_quality_non_conformances', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('nc_number', 50)->index();
                $table->uuid('quality_inspection_id')->nullable()->index();
                $table->uuid('production_order_id')->nullable()->index();
                $table->string('severity', 20)->default('major')->index();
                $table->string('defect_type', 100);
                $table->text('description');
                $table->string('disposition', 50)->default('investigating')->index();
                $table->text('corrective_action')->nullable();
                $table->string('status', 30)->default('open')->index();
                $table->uuid('reported_by')->nullable();
                $table->uuid('resolved_by')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'nc_number']);
            });
        }

        // 24. Production Cost Entries
        if (! Schema::hasTable('mfg_production_costs')) {
            Schema::create('mfg_production_costs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('production_order_id')->index();
                $table->string('cost_type', 30)->index();
                $table->decimal('standard_cost', 15, 4)->default(0.0000);
                $table->decimal('actual_cost', 15, 4)->default(0.0000);
                $table->decimal('variance', 15, 4)->default(0.0000);
                $table->boolean('is_posted_to_gl')->default(false)->index();
                $table->uuid('gl_journal_id')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('production_order_id')->references('id')->on('mfg_production_orders')->cascadeOnDelete();
            });
        }

        // 25. Work Center Capacities Calendar
        if (! Schema::hasTable('mfg_work_center_capacities')) {
            Schema::create('mfg_work_center_capacities', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('work_center_id')->index();
                $table->date('calendar_date')->index();
                $table->decimal('available_hours', 8, 2)->default(8.00);
                $table->decimal('booked_hours', 8, 2)->default(0.00);
                $table->decimal('utilization_rate', 5, 2)->default(0.00);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('work_center_id')->references('id')->on('mfg_work_centers')->cascadeOnDelete();
                $table->unique(['tenant_id', 'work_center_id', 'calendar_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mfg_work_center_capacities');
        Schema::dropIfExists('mfg_production_costs');
        Schema::dropIfExists('mfg_quality_non_conformances');
        Schema::dropIfExists('mfg_quality_inspections');
        Schema::dropIfExists('mfg_quality_characteristics');
        Schema::dropIfExists('mfg_quality_plans');
    }
};
