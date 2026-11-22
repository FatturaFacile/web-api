<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MES operator board (#183): the lifecycle state of a phase (an order operation worked at a work centre), its
 * timestamped time intervals, the production and scrap declarations, the material lots consumed and the receipts that
 * make every command idempotent. Dialect-agnostic: runs on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mfg_mes_phases')) {
            Schema::create('mfg_mes_phases', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable()->index();
                $table->uuid('production_order_id')->index();
                $table->uuid('production_order_operation_id');
                $table->uuid('work_center_id')->index();
                $table->uuid('machine_id')->nullable()->index();
                $table->uuid('operator_id')->nullable()->index();
                $table->string('status', 20)->index();
                $table->string('resume_to', 20)->nullable();
                $table->string('machine_mode', 20)->default('attended');
                $table->unsignedInteger('version')->default(1);
                $table->timestamp('taken_over_at');
                $table->timestamp('closed_at')->nullable();
                $table->uuid('confirmation_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();

                $table->foreign('production_order_id')->references('id')->on('mfg_production_orders')->cascadeOnDelete();
                $table->foreign('production_order_operation_id')->references('id')->on('mfg_production_order_operations')->cascadeOnDelete();
                $table->unique(['tenant_id', 'production_order_operation_id'], 'mfg_mes_phases_operation_unique');
                $table->index(['tenant_id', 'work_center_id', 'status'], 'mfg_mes_phases_board_index');
            });
        }

        if (! Schema::hasTable('mfg_mes_time_logs')) {
            Schema::create('mfg_mes_time_logs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable()->index();
                $table->uuid('phase_id');
                $table->uuid('operator_id')->nullable()->index();
                $table->string('category', 20);
                $table->boolean('machine_attended')->default(true);
                $table->string('suspension_reason', 30)->nullable();
                $table->timestamp('started_at');
                $table->timestamp('ended_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();

                $table->foreign('phase_id')->references('id')->on('mfg_mes_phases')->cascadeOnDelete();
                $table->index(['phase_id', 'ended_at'], 'mfg_mes_time_logs_open_index');
            });
        }

        if (! Schema::hasTable('mfg_mes_production_declarations')) {
            Schema::create('mfg_mes_production_declarations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable()->index();
                $table->uuid('phase_id');
                $table->uuid('operator_id')->nullable();
                $table->decimal('good_quantity', 15, 4)->default(0);
                $table->decimal('scrap_quantity', 15, 4)->default(0);
                $table->string('idempotency_key', 64);
                $table->timestamp('declared_at');
                $table->timestamps();
                $table->softDeletes();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();

                $table->foreign('phase_id')->references('id')->on('mfg_mes_phases')->cascadeOnDelete();
                $table->unique(['tenant_id', 'idempotency_key'], 'mfg_mes_declarations_key_unique');
            });
        }

        if (! Schema::hasTable('mfg_mes_scrap_declarations')) {
            Schema::create('mfg_mes_scrap_declarations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable()->index();
                $table->uuid('phase_id')->index();
                $table->uuid('production_declaration_id');
                $table->decimal('quantity', 15, 4);
                $table->string('defect_reason', 30);
                $table->string('note', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();

                $table->foreign('production_declaration_id')->references('id')->on('mfg_mes_production_declarations')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('mfg_mes_material_consumptions')) {
            Schema::create('mfg_mes_material_consumptions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable()->index();
                $table->uuid('phase_id');
                $table->uuid('production_order_line_id')->index();
                $table->uuid('mfg_item_id')->index();
                $table->uuid('lot_id')->nullable()->index();
                $table->string('lot_code', 64)->nullable();
                $table->decimal('quantity', 15, 4);
                $table->uuid('material_movement_id')->nullable();
                $table->uuid('operator_id')->nullable();
                $table->string('idempotency_key', 64);
                $table->timestamp('consumed_at');
                $table->timestamp('settled_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();

                $table->foreign('phase_id')->references('id')->on('mfg_mes_phases')->cascadeOnDelete();
                $table->unique(['tenant_id', 'idempotency_key'], 'mfg_mes_consumptions_key_unique');
            });
        }

        if (! Schema::hasTable('mfg_mes_command_receipts')) {
            Schema::create('mfg_mes_command_receipts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable()->index();
                $table->string('idempotency_key', 64);
                $table->string('command', 40);
                $table->uuid('phase_id')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();

                $table->unique(['tenant_id', 'idempotency_key'], 'mfg_mes_receipts_key_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mfg_mes_command_receipts');
        Schema::dropIfExists('mfg_mes_material_consumptions');
        Schema::dropIfExists('mfg_mes_scrap_declarations');
        Schema::dropIfExists('mfg_mes_production_declarations');
        Schema::dropIfExists('mfg_mes_time_logs');
        Schema::dropIfExists('mfg_mes_phases');
    }
};
