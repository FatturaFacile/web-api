<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable audit trail: an INSERT-only, hash-chained ledger per tenant (audit_logs), the WORM archive batches and the
 * database triggers that physically refuse every UPDATE and DELETE of the ledger.
 *
 * The earlier audit_logs table was a plain mutable log that nothing wrote to: it is replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropTriggers();
        Schema::dropIfExists('audit_worm_batch_entries');
        Schema::dropIfExists('audit_worm_batches');
        Schema::dropIfExists('audit_logs');

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('sequence_number');
            $table->string('event_type', 40);
            $table->string('auditable_type');
            $table->string('auditable_id')->nullable();
            $table->string('actor_type', 20);
            $table->string('actor_id')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('actor_role', 60)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->uuid('request_id')->nullable();
            $table->string('route_name')->nullable();
            $table->longText('old_values_encrypted')->nullable();
            $table->longText('new_values_encrypted')->nullable();
            $table->jsonb('changed_fields')->nullable();
            $table->jsonb('context_metadata')->nullable();
            $table->char('previous_hash', 64);
            $table->char('current_hash', 64)->unique();
            $table->char('hmac_signature', 64);
            $table->dateTime('occurred_at', 6);

            $table->unique(['tenant_id', 'sequence_number'], 'audit_logs_tenant_sequence_unique');
            $table->index(['tenant_id', 'auditable_type', 'auditable_id'], 'audit_logs_tenant_entity_index');
            $table->index('auditable_type');
            $table->index('auditable_id');
            $table->index('actor_id');
            $table->index('event_type');
            $table->index('occurred_at');
        });

        Schema::create('audit_worm_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('from_sequence');
            $table->unsignedBigInteger('to_sequence');
            $table->unsignedInteger('record_count');
            $table->char('merkle_root_hash', 64);
            $table->string('storage_disk');
            $table->string('storage_path');
            $table->string('manifest_path');
            $table->dateTime('retention_until');
            $table->dateTime('sealed_at');

            $table->unique(['tenant_id', 'from_sequence'], 'audit_worm_batches_tenant_from_unique');
        });

        // The link lives in its own table so that audit_logs never needs an UPDATE.
        Schema::create('audit_worm_batch_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('batch_id')->constrained('audit_worm_batches')->restrictOnDelete();
            $table->foreignUuid('audit_log_id')->unique()->constrained('audit_logs')->restrictOnDelete();

            $table->index(['tenant_id', 'batch_id'], 'audit_worm_batch_entries_tenant_batch_index');
        });

        $this->createTriggers();
    }

    public function down(): void
    {
        $this->dropTriggers();

        Schema::dropIfExists('audit_worm_batch_entries');
        Schema::dropIfExists('audit_worm_batches');
        Schema::dropIfExists('audit_logs');
    }

    private function createTriggers(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_audit_log_mutation()
                RETURNS TRIGGER AS $$
                BEGIN
                    RAISE EXCEPTION 'Audit logs are strictly immutable (WORM compliance)';
                END;
                $$ LANGUAGE plpgsql;

                DROP TRIGGER IF EXISTS trg_prevent_audit_log_mutation ON audit_logs;
                CREATE TRIGGER trg_prevent_audit_log_mutation
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION prevent_audit_log_mutation();

                DROP TRIGGER IF EXISTS trg_prevent_audit_log_truncate ON audit_logs;
                CREATE TRIGGER trg_prevent_audit_log_truncate
                BEFORE TRUNCATE ON audit_logs
                FOR EACH STATEMENT EXECUTE FUNCTION prevent_audit_log_mutation();
            SQL);

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER prevent_audit_log_update BEFORE UPDATE ON audit_logs BEGIN SELECT RAISE(ABORT, 'Audit logs are strictly immutable'); END;");
            DB::unprepared("CREATE TRIGGER prevent_audit_log_delete BEFORE DELETE ON audit_logs BEGIN SELECT RAISE(ABORT, 'Audit logs are strictly immutable'); END;");
        }
    }

    private function dropTriggers(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            if (Schema::hasTable('audit_logs')) {
                DB::unprepared(<<<'SQL'
                    DROP TRIGGER IF EXISTS trg_prevent_audit_log_truncate ON audit_logs;
                    DROP TRIGGER IF EXISTS trg_prevent_audit_log_mutation ON audit_logs;
                SQL);
            }
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_audit_log_mutation()');

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS prevent_audit_log_update');
            DB::unprepared('DROP TRIGGER IF EXISTS prevent_audit_log_delete');
        }
    }
};
