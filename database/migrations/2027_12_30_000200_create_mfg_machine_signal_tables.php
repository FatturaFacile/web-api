<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Industria 4.0 connectivity (issue #184): the per-machine signal mapping tables used to turn raw OPC UA, MQTT and
 * Modbus gateway messages into machine states and numeric readings, and the readings themselves (piece counter,
 * spindle speed, energy). Portable on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mfg_machine_signal_maps')) {
            Schema::create('mfg_machine_signal_maps', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable()->index();
                $table->uuid('machine_id')->index();
                $table->uuid('gateway_id')->nullable()->index();
                $table->string('protocol', 10);
                $table->string('source_key', 255);
                $table->string('signal', 20);
                $table->string('data_type', 10)->nullable();
                $table->string('word_order', 6)->default('big');
                $table->decimal('scale_factor', 18, 6)->default(1);
                $table->decimal('offset_value', 18, 6)->default(0);
                $table->string('value_path', 255)->nullable();
                $table->json('state_map')->nullable();
                $table->string('unit', 12)->nullable();
                $table->boolean('is_active')->default(true);
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'protocol', 'is_active'], 'mfg_machine_signal_maps_lookup');
            });
        }

        if (! Schema::hasTable('mfg_machine_readings')) {
            Schema::create('mfg_machine_readings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable()->index();
                $table->uuid('gateway_id')->index();
                $table->uuid('machine_id')->index();
                $table->uuid('reading_uuid');
                $table->string('signal', 20);
                $table->decimal('value', 20, 4);
                $table->string('unit', 12)->nullable();
                $table->timestamp('occurred_at');
                $table->uuid('production_order_operation_id')->nullable()->index();
                $table->json('payload')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'reading_uuid'], 'mfg_machine_readings_uuid_unique');
                $table->index(['machine_id', 'signal', 'occurred_at'], 'mfg_machine_readings_series');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mfg_machine_readings');
        Schema::dropIfExists('mfg_machine_signal_maps');
    }
};
