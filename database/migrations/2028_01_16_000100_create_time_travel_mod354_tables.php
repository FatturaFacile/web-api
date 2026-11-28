<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #600 (Mod 354) — bi-temporal event sourcing and time-travel audit engine. The append-only event store (valid time and
 * recording time, SHA-256 hash chain per tenant stream), the materialized checkpoints, the Merkle anchors that seal the audit
 * periods and the what-if valuation runs. Timestamps are stored as UTC `timestamp(6)` (identical on SQLite and PostgreSQL). The
 * immutability of the event store and of the anchors is enforced by the application (ProtectsRecords) on every database and,
 * on PostgreSQL, additionally by a trigger that refuses UPDATE and DELETE. tenant_id is nullable like on every table (the policy of the
 * schema); the models never accept a null tenant, so a row without tenant is neither readable nor writable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('es_bitemporal_event_streams')) {
            Schema::create('es_bitemporal_event_streams', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
                $table->string('stream_id', 80);
                $table->string('aggregate_type', 32);
                $table->string('aggregate_id', 64);
                $table->unsignedBigInteger('sequence_number');
                $table->string('event_type', 64);
                $table->timestamp('valid_at', 6);
                $table->timestamp('recorded_at', 6);
                $table->json('payload');
                $table->json('metadata')->nullable();
                $table->string('actor_user_id', 64)->nullable();
                $table->string('previous_event_hash', 64);
                $table->string('cryptographic_chain_hash', 64);

                $table->unique(['tenant_id', 'stream_id', 'sequence_number'], 'es_events_stream_seq_uq');
                $table->index(['tenant_id', 'aggregate_type', 'valid_at', 'recorded_at'], 'es_events_bitemporal_idx');
                $table->index(['tenant_id', 'aggregate_type', 'aggregate_id'], 'es_events_aggregate_idx');
                $table->index(['tenant_id', 'recorded_at'], 'es_events_recorded_idx');
            });
        }

        if (! Schema::hasTable('es_materialized_checkpoints')) {
            Schema::create('es_materialized_checkpoints', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
                $table->string('aggregate_type', 32);
                $table->timestamp('valid_at', 6);
                $table->timestamp('recorded_at', 6);
                $table->json('state_payload');
                $table->string('merkle_root', 64);
                $table->unsignedBigInteger('event_count');
                $table->unsignedBigInteger('last_sequence_number');
                $table->timestamp('created_at')->nullable();

                $table->index(['tenant_id', 'aggregate_type', 'valid_at', 'recorded_at'], 'es_checkpoints_coord_idx');
            });
        }

        if (! Schema::hasTable('es_merkle_audit_anchors')) {
            Schema::create('es_merkle_audit_anchors', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
                $table->string('period_type', 8);
                $table->date('period_start');
                $table->date('period_end');
                $table->string('merkle_root', 64);
                $table->unsignedBigInteger('event_count');
                $table->timestamp('sealed_at', 6);

                $table->unique(['tenant_id', 'period_type', 'period_start'], 'es_anchors_period_uq');
            });
        }

        if (! Schema::hasTable('es_whatif_simulation_runs')) {
            Schema::create('es_whatif_simulation_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
                $table->string('simulation_name', 160);
                $table->timestamp('target_valid_at', 6);
                $table->timestamp('target_known_at', 6);
                $table->string('valuation_method', 8);
                $table->json('baseline_summary');
                $table->json('simulated_summary');
                $table->json('delta_summary');
                $table->json('items')->nullable();
                $table->string('created_by', 64)->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['tenant_id', 'created_at'], 'es_whatif_tenant_created_idx');
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("
                CREATE OR REPLACE FUNCTION prevent_time_travel_mutation()
                RETURNS TRIGGER AS $$
                BEGIN
                    RAISE EXCEPTION 'Bi-temporal event store records are strictly immutable (GeBueV / Art. 957a CO).';
                END;
                $$ LANGUAGE plpgsql;

                DROP TRIGGER IF EXISTS trg_es_events_immutable ON es_bitemporal_event_streams;
                CREATE TRIGGER trg_es_events_immutable BEFORE UPDATE OR DELETE ON es_bitemporal_event_streams
                FOR EACH ROW EXECUTE FUNCTION prevent_time_travel_mutation();

                DROP TRIGGER IF EXISTS trg_es_anchors_immutable ON es_merkle_audit_anchors;
                CREATE TRIGGER trg_es_anchors_immutable BEFORE UPDATE OR DELETE ON es_merkle_audit_anchors
                FOR EACH ROW EXECUTE FUNCTION prevent_time_travel_mutation();
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('
                DROP TRIGGER IF EXISTS trg_es_events_immutable ON es_bitemporal_event_streams;
                DROP TRIGGER IF EXISTS trg_es_anchors_immutable ON es_merkle_audit_anchors;
                DROP FUNCTION IF EXISTS prevent_time_travel_mutation();
            ');
        }

        Schema::dropIfExists('es_whatif_simulation_runs');
        Schema::dropIfExists('es_merkle_audit_anchors');
        Schema::dropIfExists('es_materialized_checkpoints');
        Schema::dropIfExists('es_bitemporal_event_streams');
    }
};
