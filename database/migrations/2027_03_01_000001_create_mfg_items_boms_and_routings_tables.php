<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Manufacturing Items
        if (! Schema::hasTable('mfg_items')) {
            Schema::create('mfg_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('item_code', 50)->index();
                $table->string('name', 200);
                $table->text('description')->nullable();
                $table->string('item_type', 30)->default('raw_material')->index();
                $table->uuid('stock_item_id')->nullable()->index();
                $table->uuid('default_bom_id')->nullable()->index();
                $table->uuid('default_routing_id')->nullable()->index();
                $table->string('uom', 20)->default('PCS');
                $table->decimal('standard_cost', 15, 4)->default(0.0000);
                $table->decimal('current_cost', 15, 4)->default(0.0000);
                $table->decimal('scrap_rate', 5, 2)->default(0.00);
                $table->decimal('safety_stock', 15, 4)->default(0.0000);
                $table->decimal('min_lot_size', 15, 4)->default(1.0000);
                $table->decimal('max_lot_size', 15, 4)->nullable();
                $table->integer('lead_time_days')->default(1);
                $table->boolean('is_active')->default(true)->index();
                $table->json('custom_attributes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'item_code']);
            });
        }

        // 2. Bills of Material (BOM) Header
        if (! Schema::hasTable('mfg_boms')) {
            Schema::create('mfg_boms', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('bom_number', 50)->index();
                $table->uuid('mfg_item_id')->index();
                $table->string('name', 200);
                $table->string('revision', 20)->default('A');
                $table->integer('version')->default(1);
                $table->string('bom_type', 30)->default('standard')->index();
                $table->string('status', 30)->default('draft')->index();
                $table->decimal('base_quantity', 15, 4)->default(1.0000);
                $table->string('uom', 20)->default('PCS');
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->boolean('is_locked')->default(false);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'bom_number', 'revision']);
            });
        }

        // 3. BOM Components / Lines
        if (! Schema::hasTable('mfg_bom_components')) {
            Schema::create('mfg_bom_components', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('bom_id')->index();
                $table->uuid('component_item_id')->index();
                $table->integer('sequence')->default(10);
                $table->decimal('quantity_per', 15, 4)->default(1.0000);
                $table->string('uom', 20)->default('PCS');
                $table->decimal('scrap_percentage', 5, 2)->default(0.00);
                $table->decimal('yield_percentage', 5, 2)->default(100.00);
                $table->boolean('is_phantom')->default(false);
                $table->uuid('alternate_for_id')->nullable()->index();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('bom_id')->references('id')->on('mfg_boms')->cascadeOnDelete();
            });
        }

        // 4. Engineering Change Orders (ECO)
        if (! Schema::hasTable('mfg_engineering_change_orders')) {
            Schema::create('mfg_engineering_change_orders', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('eco_number', 50)->index();
                $table->string('title', 200);
                $table->text('reason');
                $table->text('description')->nullable();
                $table->string('change_type', 50)->default('bom_change');
                $table->string('status', 30)->default('draft')->index();
                $table->date('effective_date')->nullable();
                $table->uuid('requested_by')->nullable();
                $table->uuid('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('implemented_at')->nullable();
                $table->json('change_payload')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'eco_number']);
            });
        }

        // 5. Work Centers
        if (! Schema::hasTable('mfg_work_centers')) {
            Schema::create('mfg_work_centers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('work_center_code', 50)->index();
                $table->string('name', 200);
                $table->text('description')->nullable();
                $table->string('department_code', 50)->nullable();
                $table->decimal('capacity_hours_per_day', 6, 2)->default(8.00);
                $table->decimal('efficiency_rate', 5, 2)->default(100.00);
                $table->decimal('availability_rate', 5, 2)->default(100.00);
                $table->decimal('hourly_cost_rate', 15, 4)->default(0.0000);
                $table->decimal('hourly_overhead_rate', 15, 4)->default(0.0000);
                $table->string('status', 30)->default('active')->index();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'work_center_code']);
            });
        }

        // 6. Machine Centers
        if (! Schema::hasTable('mfg_machine_centers')) {
            Schema::create('mfg_machine_centers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('machine_code', 50)->index();
                $table->string('name', 200);
                $table->uuid('work_center_id')->index();
                $table->uuid('asset_id')->nullable()->index();
                $table->decimal('hourly_rate', 15, 4)->default(0.0000);
                $table->decimal('power_kw', 8, 2)->default(0.00);
                $table->string('status', 30)->default('active')->index();
                $table->timestamp('last_serviced_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('work_center_id')->references('id')->on('mfg_work_centers')->cascadeOnDelete();
                $table->unique(['tenant_id', 'machine_code']);
            });
        }

        // 7. Routings Header
        if (! Schema::hasTable('mfg_routings')) {
            Schema::create('mfg_routings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('routing_number', 50)->index();
                $table->uuid('mfg_item_id')->index();
                $table->string('name', 200);
                $table->string('revision', 20)->default('A');
                $table->string('status', 30)->default('draft')->index();
                $table->boolean('is_default')->default(false);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'routing_number', 'revision']);
            });
        }

        // 8. Operations
        if (! Schema::hasTable('mfg_operations')) {
            Schema::create('mfg_operations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('routing_id')->index();
                $table->integer('sequence')->default(10);
                $table->string('operation_code', 50)->index();
                $table->string('name', 200);
                $table->uuid('work_center_id')->index();
                $table->uuid('machine_center_id')->nullable()->index();
                $table->decimal('setup_time_minutes', 8, 2)->default(0.00);
                $table->decimal('run_time_minutes_per_unit', 8, 2)->default(0.00);
                $table->decimal('labor_time_minutes_per_unit', 8, 2)->default(0.00);
                $table->decimal('queue_time_minutes', 8, 2)->default(0.00);
                $table->decimal('wait_time_minutes', 8, 2)->default(0.00);
                $table->decimal('teardown_time_minutes', 8, 2)->default(0.00);
                $table->decimal('standard_labor_rate', 15, 4)->default(0.0000);
                $table->decimal('standard_machine_rate', 15, 4)->default(0.0000);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('routing_id')->references('id')->on('mfg_routings')->cascadeOnDelete();
                $table->foreign('work_center_id')->references('id')->on('mfg_work_centers')->cascadeOnDelete();
            });
        }

        // 9. Production Versions
        if (! Schema::hasTable('mfg_production_versions')) {
            Schema::create('mfg_production_versions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('version_code', 50)->index();
                $table->uuid('mfg_item_id')->index();
                $table->uuid('bom_id')->index();
                $table->uuid('routing_id')->index();
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->decimal('min_lot_size', 15, 4)->default(1.0000);
                $table->decimal('max_lot_size', 15, 4)->nullable();
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('bom_id')->references('id')->on('mfg_boms')->cascadeOnDelete();
                $table->foreign('routing_id')->references('id')->on('mfg_routings')->cascadeOnDelete();
                $table->unique(['tenant_id', 'mfg_item_id', 'version_code']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mfg_production_versions');
        Schema::dropIfExists('mfg_operations');
        Schema::dropIfExists('mfg_routings');
        Schema::dropIfExists('mfg_machine_centers');
        Schema::dropIfExists('mfg_work_centers');
        Schema::dropIfExists('mfg_engineering_change_orders');
        Schema::dropIfExists('mfg_bom_components');
        Schema::dropIfExists('mfg_boms');
        Schema::dropIfExists('mfg_items');
    }
};
