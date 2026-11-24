<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Industry 4.0 / MES integration (GitHub issue #184), Laravel side:
 *
 * - the ideal cycle time of an order operation and the machine time source of a tenant;
 * - the structured downtime / scrap reason-code catalogue and the UTC shift instances materialised from the weekly
 *   work-centre calendars;
 * - the change log (`mfg_mes_sync_changes`) the MES Core pulls reference data from;
 * - the inbound feedback of the MES Core: inbox, latest OEE snapshots, their append-only revisions, machine-time
 *   delta postings and production-count proposals.
 *
 * Portable between SQLite and PostgreSQL: plain column types, no partial indexes and no engine specific SQL. Every
 * table has the nullable, leading-indexed `tenant_id` of the tenant policy of the repository.
 */
return new class extends Migration
{
    /**
     * Creates the MES integration tables and columns.
     */
    public function up(): void
    {
        Schema::table('mfg_production_order_operations', function (Blueprint $table): void {
            if (! Schema::hasColumn('mfg_production_order_operations', 'ideal_cycle_time_ms')) {
                // Explicit ideal cycle time in milliseconds per piece. When null the MES derives it from the planned run time.
                $table->decimal('ideal_cycle_time_ms', 12, 3)->nullable();
            }
        });

        Schema::table('mfg_accounting_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('mfg_accounting_settings', 'machine_time_source')) {
                // `manual`: machine time comes from confirmations; `mes`: the MES machine-time postings are costed.
                $table->string('machine_time_source', 10)->default('manual');
            }
        });

        Schema::create('mfg_reason_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('code', 50);
            $table->string('category', 30);
            $table->string('classification', 30);
            $table->boolean('is_active')->default(true);
            $this->auditColumns($table);

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('mfg_reason_code_labels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('reason_code_id')->index();
            $table->string('locale', 10);
            $table->string('label', 200);
            $this->auditColumns($table);

            $table->unique(['reason_code_id', 'locale']);
            $table->foreign('reason_code_id')->references('id')->on('mfg_reason_codes')->cascadeOnDelete();
        });

        Schema::create('mfg_shift_instances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('scope_type', 20);
            $table->uuid('scope_id');
            $table->string('shift_code', 30);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('timezone', 64);
            $table->json('non_production')->nullable();
            $this->auditColumns($table);

            $table->unique(['tenant_id', 'scope_type', 'scope_id', 'shift_code', 'starts_at'], 'mfg_shift_instances_natural');
            $table->index(['tenant_id', 'scope_id', 'starts_at']);
        });

        Schema::create('mfg_mes_sync_changes', function (Blueprint $table): void {
            $table->bigIncrements('seq');
            $table->uuid('tenant_id')->nullable();
            $table->string('resource', 30);
            $table->uuid('entity_id');
            $table->string('operation', 10);
            $table->timestamp('changed_at');

            $table->index(['tenant_id', 'resource', 'seq']);
            $table->index(['tenant_id', 'resource', 'entity_id']);
        });

        // Highest change seq pruned per tenant: a pull cursor below it can no longer be served (410 Gone).
        Schema::create('mfg_mes_sync_floors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->unique();
            $table->unsignedBigInteger('pruned_through_seq')->default(0);
            $table->timestamps();
        });

        Schema::create('mfg_mes_inbox', function (Blueprint $table): void {
            $table->uuid('event_id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('event_type', 60);
            $table->timestamp('processed_at');
        });

        Schema::create('mfg_mes_oee_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('machine_id')->index();
            $table->uuid('production_order_operation_id')->nullable()->index();
            $table->string('scope', 20);
            $table->string('scope_key', 64);
            $table->unsignedInteger('revision');
            $table->unsignedInteger('policy_version');
            $table->json('metrics');
            $table->timestamp('as_of_event_time');
            $this->auditColumns($table);

            $table->unique(['tenant_id', 'machine_id', 'scope', 'scope_key'], 'mfg_mes_oee_latest');
        });

        Schema::create('mfg_mes_oee_snapshot_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('snapshot_id')->index();
            $table->uuid('event_id')->unique();
            $table->unsignedInteger('revision');
            $table->json('metrics');
            $table->timestamp('received_at');

            $table->index(['snapshot_id', 'revision']);
        });

        Schema::create('mfg_mes_machine_time_postings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('production_order_operation_id');
            $table->uuid('machine_id');
            $table->uuid('snapshot_id')->index();
            $table->unsignedInteger('revision');
            $table->decimal('delta_run_minutes', 12, 2);
            $table->decimal('delta_setup_minutes', 12, 2);
            $table->decimal('delta_downtime_minutes', 12, 2);
            $table->decimal('delta_machine_cost', 15, 4);
            $table->decimal('delta_energy_kwh', 15, 4)->nullable();
            $table->date('posting_date');
            $table->string('reason', 30);
            $this->auditColumns($table);

            $table->index(['tenant_id', 'production_order_operation_id', 'machine_id'], 'mfg_mes_postings_operation');
        });

        Schema::create('mfg_mes_count_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('production_order_operation_id')->index();
            $table->decimal('good_count', 15, 4);
            $table->decimal('scrap_count', 15, 4);
            $table->string('status', 20)->default('pending');
            $table->timestamp('as_of_event_time');
            $this->auditColumns($table);
        });
    }

    /**
     * Drops the MES integration tables and columns.
     */
    public function down(): void
    {
        foreach ([
            'mfg_mes_count_proposals', 'mfg_mes_machine_time_postings', 'mfg_mes_oee_snapshot_revisions', 'mfg_mes_oee_snapshots',
            'mfg_mes_inbox', 'mfg_mes_sync_floors', 'mfg_mes_sync_changes', 'mfg_shift_instances', 'mfg_reason_code_labels', 'mfg_reason_codes',
        ] as $tableName) {
            Schema::dropIfExists($tableName);
        }

        Schema::table('mfg_accounting_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('mfg_accounting_settings', 'machine_time_source')) {
                $table->dropColumn('machine_time_source');
            }
        });

        Schema::table('mfg_production_order_operations', function (Blueprint $table): void {
            if (Schema::hasColumn('mfg_production_order_operations', 'ideal_cycle_time_ms')) {
                $table->dropColumn('ideal_cycle_time_ms');
            }
        });
    }

    /**
     * The six audit columns every business table carries (see AuditColumnsTest). The inbox, the revision log and the
     * change log are append-only technical tables and are excluded from the rule there.
     */
    private function auditColumns(Blueprint $table): void
    {
        $table->timestamps();
        $table->softDeletes();
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
    }
};
