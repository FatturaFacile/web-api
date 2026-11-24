<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 061: work centre hierarchy and cost rates, machines with Industria 4.0 telemetry, working calendars with shifts
 * and planned shutdowns, routing operations with overlap and subcontracting, BOM component links and OEE logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mfg_work_centers', function (Blueprint $table): void {
            foreach ([
                'warehouse_id' => fn () => $table->uuid('warehouse_id')->nullable()->index(),
                'cost_center_id' => fn () => $table->uuid('cost_center_id')->nullable()->index(),
                'working_calendar_id' => fn () => $table->uuid('working_calendar_id')->nullable()->index(),
                'parent_work_center_id' => fn () => $table->uuid('parent_work_center_id')->nullable()->index(),
                'parallel_capacity_units' => fn () => $table->unsignedSmallInteger('parallel_capacity_units')->default(1),
                'oee_target_percent' => fn () => $table->decimal('oee_target_percent', 5, 2)->default(80),
                'availability_target_percent' => fn () => $table->decimal('availability_target_percent', 5, 2)->nullable(),
                'performance_target_percent' => fn () => $table->decimal('performance_target_percent', 5, 2)->nullable(),
                'quality_target_percent' => fn () => $table->decimal('quality_target_percent', 5, 2)->nullable(),
                'hourly_setup_cost_rate' => fn () => $table->decimal('hourly_setup_cost_rate', 12, 4)->default(0),
                'hourly_machine_run_cost_rate' => fn () => $table->decimal('hourly_machine_run_cost_rate', 12, 4)->default(0),
                'currency_code' => fn () => $table->string('currency_code', 3)->default('EUR'),
                'default_queue_wait_minutes' => fn () => $table->unsignedInteger('default_queue_wait_minutes')->default(0),
                'default_move_time_minutes' => fn () => $table->unsignedInteger('default_move_time_minutes')->default(0),
                'alternate_work_center_ids' => fn () => $table->json('alternate_work_center_ids')->nullable(),
            ] as $column => $define) {
                if (! Schema::hasColumn('mfg_work_centers', $column)) {
                    $define();
                }
            }
        });

        Schema::table('mfg_machine_centers', function (Blueprint $table): void {
            foreach ([
                'manufacturer' => fn () => $table->string('manufacturer', 120)->nullable(),
                'model' => fn () => $table->string('model', 120)->nullable(),
                'serial_number' => fn () => $table->string('serial_number', 120)->nullable(),
                'maintenance_schedule_id' => fn () => $table->uuid('maintenance_schedule_id')->nullable()->index(),
                'is_industria_40_enabled' => fn () => $table->boolean('is_industria_40_enabled')->default(false),
                'is_transizione_50_enabled' => fn () => $table->boolean('is_transizione_50_enabled')->default(false),
                'telemetry_device_id' => fn () => $table->string('telemetry_device_id', 120)->nullable(),
                'telemetry_endpoint_key' => fn () => $table->string('telemetry_endpoint_key', 120)->nullable(),
                'telemetry_last_seen_at' => fn () => $table->timestamp('telemetry_last_seen_at')->nullable(),
                'telemetry_status' => fn () => $table->string('telemetry_status', 20)->nullable(),
                'ideal_cycle_time_seconds_per_unit' => fn () => $table->decimal('ideal_cycle_time_seconds_per_unit', 10, 4)->nullable(),
                'parallel_capacity_units' => fn () => $table->unsignedSmallInteger('parallel_capacity_units')->default(1),
                'efficiency_factor_percent' => fn () => $table->decimal('efficiency_factor_percent', 5, 2)->nullable(),
                'hourly_machine_run_cost_rate_override' => fn () => $table->decimal('hourly_machine_run_cost_rate_override', 12, 4)->nullable(),
            ] as $column => $define) {
                if (! Schema::hasColumn('mfg_machine_centers', $column)) {
                    $define();
                }
            }
        });

        Schema::table('mfg_routings', function (Blueprint $table): void {
            if (! Schema::hasColumn('mfg_routings', 'bom_id')) {
                $table->uuid('bom_id')->nullable()->index();
            }
        });

        Schema::table('mfg_operations', function (Blueprint $table): void {
            foreach ([
                'operation_type' => fn () => $table->string('operation_type', 30)->default('internal_machining'),
                'post_operation_wait_minutes' => fn () => $table->decimal('post_operation_wait_minutes', 10, 3)->default(0),
                'move_time_minutes' => fn () => $table->decimal('move_time_minutes', 10, 3)->default(0),
                'overlap_transfer_batch_qty' => fn () => $table->decimal('overlap_transfer_batch_qty', 12, 4)->nullable(),
                'required_operators' => fn () => $table->unsignedSmallInteger('required_operators')->default(1),
                'alternate_work_center_ids' => fn () => $table->json('alternate_work_center_ids')->nullable(),
                'linked_quality_control_point_id' => fn () => $table->uuid('linked_quality_control_point_id')->nullable()->index(),
                'work_instruction_doc_id' => fn () => $table->uuid('work_instruction_doc_id')->nullable()->index(),
            ] as $column => $define) {
                if (! Schema::hasColumn('mfg_operations', $column)) {
                    $define();
                }
            }
        });

        // Timings need finer precision than the original (8,2) columns.
        Schema::table('mfg_operations', function (Blueprint $table): void {
            $table->decimal('setup_time_minutes', 10, 3)->default(0)->change();
            $table->decimal('run_time_minutes_per_unit', 10, 5)->default(0)->change();
            $table->decimal('queue_time_minutes', 10, 3)->default(0)->change();
        });

        if (! Schema::hasTable('mfg_working_calendars')) {
            Schema::create('mfg_working_calendars', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable()->index();
                $table->string('code', 50);
                $table->string('name', 200);
                $table->string('timezone', 64)->default('Europe/Zurich');
                $table->string('country_code', 2)->default('CH');
                $table->string('region_code', 10)->nullable();
                $table->uuid('inherit_holiday_calendar_id')->nullable()->index();
                $table->boolean('is_default')->default(false);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('mfg_working_calendar_shifts')) {
            Schema::create('mfg_working_calendar_shifts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable()->index();
                $table->uuid('working_calendar_id')->index();
                $table->unsignedTinyInteger('day_of_week');
                $table->string('shift_code', 30);
                $table->string('start_time', 5);
                $table->string('end_time', 5);
                $table->boolean('crosses_midnight')->default(false);
                $table->unsignedInteger('break_minutes')->default(0);
                $table->unsignedInteger('net_available_minutes')->default(0);
                $table->decimal('labor_multiplier', 6, 4)->default(1);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('working_calendar_id')->references('id')->on('mfg_working_calendars')->cascadeOnDelete();
                $table->unique(['working_calendar_id', 'day_of_week', 'shift_code'], 'uq_mfg_calendar_shift');
            });
        }

        if (! Schema::hasTable('mfg_working_calendar_closures')) {
            Schema::create('mfg_working_calendar_closures', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable()->index();
                $table->uuid('working_calendar_id')->index();
                $table->date('starts_on');
                $table->date('ends_on');
                $table->string('reason', 200)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('working_calendar_id')->references('id')->on('mfg_working_calendars')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('mfg_routing_step_bom_components')) {
            Schema::create('mfg_routing_step_bom_components', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable()->index();
                $table->uuid('operation_id')->index();
                $table->uuid('bom_component_id')->index();
                $table->boolean('backflush_on_completion')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('operation_id')->references('id')->on('mfg_operations')->cascadeOnDelete();
                $table->unique(['operation_id', 'bom_component_id'], 'uq_mfg_step_component');
            });
        }

        if (! Schema::hasTable('mfg_oee_logs')) {
            Schema::create('mfg_oee_logs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable()->index();
                $table->uuid('work_center_id')->index();
                $table->uuid('machine_center_id')->nullable()->index();
                $table->string('source', 20)->default('manual');
                $table->timestamp('period_start');
                $table->timestamp('period_end');
                $table->decimal('planned_downtime_minutes', 10, 3)->default(0);
                $table->decimal('unplanned_downtime_minutes', 10, 3)->default(0);
                $table->string('loss_category', 30)->nullable();
                $table->decimal('good_units', 14, 4)->default(0);
                $table->decimal('scrap_units', 14, 4)->default(0);
                $table->decimal('rework_units', 14, 4)->default(0);
                $table->string('notes', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('work_center_id')->references('id')->on('mfg_work_centers')->cascadeOnDelete();
                $table->index(['work_center_id', 'period_start'], 'idx_mfg_oee_period');
            });
        }

        // The audit stamps of the other business tables (also the BOM co-products table of Mod 060, created after the audit migration).
        foreach (['mfg_working_calendars', 'mfg_working_calendar_shifts', 'mfg_working_calendar_closures', 'mfg_routing_step_bom_components', 'mfg_oee_logs', 'mfg_bom_co_products'] as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) use ($name): void {
                foreach (['created_by', 'updated_by', 'deleted_by'] as $column) {
                    if (! Schema::hasColumn($name, $column)) {
                        $table->uuid($column)->nullable();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mfg_oee_logs');
        Schema::dropIfExists('mfg_routing_step_bom_components');
        Schema::dropIfExists('mfg_working_calendar_closures');
        Schema::dropIfExists('mfg_working_calendar_shifts');
        Schema::dropIfExists('mfg_working_calendars');
    }
};
