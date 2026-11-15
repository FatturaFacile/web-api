<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Advanced warehouse management (WMS) on top of the inventory: RF tasks, waves and wave rules, slotting rules and recommendations, cross-docking, dock doors and appointments. New tables only; warehouses, stock items and bins are referenced from the inventory module. Business rules (task states, dock conflicts, quantities) are enforced by the application, not by the engine.
     */
    public function up(): void
    {
        if (! Schema::hasTable('wms_dock_doors')) {
            Schema::create('wms_dock_doors', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->string('code', 30);
                $table->string('name', 120)->nullable();
                $table->string('direction', 30)->default('both');
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'warehouse_id', 'code']);
            });
        }

        if (! Schema::hasTable('wms_wave_rules')) {
            Schema::create('wms_wave_rules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->string('name', 120);
                $table->integer('min_priority')->nullable();
                $table->integer('due_within_hours')->nullable();
                $table->string('reference_prefix', 60)->nullable();
                $table->integer('min_tasks')->default(1);
                $table->integer('max_tasks')->default(50);
                $table->integer('priority')->default(100);
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'warehouse_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('wms_waves')) {
            Schema::create('wms_waves', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->foreignUuid('wave_rule_id')->nullable()->constrained('wms_wave_rules')->nullOnDelete();
                $table->string('number', 40)->index();
                $table->string('status', 30)->default('planned')->index();
                $table->integer('task_count')->default(0);
                $table->dateTime('released_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'warehouse_id', 'status']);
            });
        }

        if (! Schema::hasTable('wms_tasks')) {
            Schema::create('wms_tasks', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->string('task_type', 30)->index();
                $table->string('status', 30)->default('open')->index();
                $table->integer('priority')->default(50);
                $table->foreignUuid('stock_item_id')->nullable()->constrained('stock_items')->nullOnDelete();
                $table->foreignUuid('source_bin_id')->nullable()->constrained('warehouse_bins')->nullOnDelete();
                $table->foreignUuid('destination_bin_id')->nullable()->constrained('warehouse_bins')->nullOnDelete();
                $table->decimal('planned_quantity', 14, 4);
                $table->decimal('confirmed_quantity', 14, 4)->default('0');
                $table->uuid('assigned_to')->nullable()->index();
                $table->foreignUuid('wave_id')->nullable()->constrained('wms_waves')->nullOnDelete();
                $table->string('reference', 100)->nullable();
                $table->dateTime('due_at')->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->string('short_reason', 255)->nullable();
                $table->string('skip_reason', 255)->nullable();
                $table->integer('skip_count')->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'warehouse_id', 'status', 'priority']);
                $table->index(['tenant_id', 'assigned_to', 'status']);
            });
        }

        if (! Schema::hasTable('wms_slotting_rules')) {
            Schema::create('wms_slotting_rules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->string('name', 120);
                $table->string('abc_class', 30)->nullable();
                $table->decimal('min_velocity', 14, 4)->nullable();
                $table->decimal('max_velocity', 14, 4)->nullable();
                $table->string('target_bin_type', 30);
                $table->foreignUuid('target_zone_id')->nullable()->constrained('warehouse_zones')->nullOnDelete();
                $table->integer('priority')->default(100);
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'warehouse_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('wms_slotting_recommendations')) {
            Schema::create('wms_slotting_recommendations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->foreignUuid('stock_item_id')->constrained('stock_items')->cascadeOnDelete();
                $table->foreignUuid('slotting_rule_id')->nullable()->constrained('wms_slotting_rules')->nullOnDelete();
                $table->string('abc_class', 30);
                $table->decimal('velocity', 14, 4)->default('0');
                $table->integer('period_days')->default(30);
                $table->foreignUuid('from_bin_id')->nullable()->constrained('warehouse_bins')->nullOnDelete();
                $table->foreignUuid('to_bin_id')->constrained('warehouse_bins')->cascadeOnDelete();
                $table->string('target_bin_type', 30);
                $table->string('status', 30)->default('pending')->index();
                $table->foreignUuid('task_id')->nullable()->constrained('wms_tasks')->nullOnDelete();
                $table->dateTime('decided_at')->nullable();
                $table->string('reason', 255)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'warehouse_id', 'status']);
            });
        }

        if (! Schema::hasTable('wms_cross_dock_orders')) {
            Schema::create('wms_cross_dock_orders', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->string('number', 40)->index();
                $table->string('inbound_reference', 100);
                $table->string('outbound_reference', 100);
                $table->foreignUuid('inbound_door_id')->nullable()->constrained('wms_dock_doors')->nullOnDelete();
                $table->foreignUuid('outbound_door_id')->nullable()->constrained('wms_dock_doors')->nullOnDelete();
                $table->string('status', 30)->default('planned')->index();
                $table->dateTime('planned_inbound_at')->nullable();
                $table->dateTime('planned_outbound_at')->nullable();
                $table->dateTime('received_at')->nullable();
                $table->dateTime('shipped_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'warehouse_id', 'status']);
            });
        }

        if (! Schema::hasTable('wms_cross_dock_lines')) {
            Schema::create('wms_cross_dock_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('cross_dock_order_id')->constrained('wms_cross_dock_orders')->cascadeOnDelete();
                $table->foreignUuid('stock_item_id')->constrained('stock_items')->cascadeOnDelete();
                $table->decimal('inbound_quantity', 14, 4);
                $table->decimal('received_quantity', 14, 4)->default('0');
                $table->decimal('outbound_quantity', 14, 4)->default('0');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'cross_dock_order_id']);
            });
        }

        if (! Schema::hasTable('wms_dock_appointments')) {
            Schema::create('wms_dock_appointments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('dock_door_id')->constrained('wms_dock_doors')->cascadeOnDelete();
                $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->string('carrier_name', 160);
                $table->string('reference', 100)->nullable();
                $table->string('direction', 30);
                $table->dateTime('starts_at')->index();
                $table->dateTime('ends_at');
                $table->string('status', 30)->default('scheduled')->index();
                $table->dateTime('checked_in_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->foreignUuid('cross_dock_order_id')->nullable()->constrained('wms_cross_dock_orders')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'dock_door_id', 'starts_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_dock_appointments');
        Schema::dropIfExists('wms_cross_dock_lines');
        Schema::dropIfExists('wms_cross_dock_orders');
        Schema::dropIfExists('wms_slotting_recommendations');
        Schema::dropIfExists('wms_slotting_rules');
        Schema::dropIfExists('wms_tasks');
        Schema::dropIfExists('wms_waves');
        Schema::dropIfExists('wms_wave_rules');
        Schema::dropIfExists('wms_dock_doors');
    }
};
