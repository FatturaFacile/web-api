<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit entries carried by a restored backup: the active ledger is never replaced, so they are kept here, unchanged
     * and grouped by restore, in case the authorities ask for them.
     */
    public function up(): void
    {
        Schema::create('enterprise_audit_ledger_archive', function (Blueprint $table): void {
            $table->uuid('archive_id')->primary();
            $table->uuid('restore_id');
            $table->uuid('archived_by')->nullable();
            $table->timestampTz('archived_at');
            $table->uuid('id');
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->timestampTz('event_timestamp');
            $table->string('module_code', 30);
            $table->string('action', 50);
            $table->string('resource_type', 60);
            $table->uuid('resource_id');
            $table->uuid('actor_user_id')->nullable();
            $table->string('actor_type', 20)->default('user');
            $table->jsonb('payload_before')->nullable();
            $table->jsonb('payload_after')->nullable();
            $table->jsonb('diff_json')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('record_hash', 64);
            $table->string('previous_hash', 64);

            $table->index(['tenant_id', 'restore_id', 'event_timestamp'], 'idx_audit_archive_restore');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("
                CREATE OR REPLACE FUNCTION prevent_audit_ledger_archive_mutation()
                RETURNS TRIGGER AS $$
                BEGIN
                    RAISE EXCEPTION 'Audit ledger archive records are strictly immutable.';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER trg_prevent_audit_ledger_archive_mutation
                BEFORE UPDATE OR DELETE ON enterprise_audit_ledger_archive
                FOR EACH ROW EXECUTE FUNCTION prevent_audit_ledger_archive_mutation();

                CREATE TRIGGER trg_prevent_audit_ledger_archive_truncate
                BEFORE TRUNCATE ON enterprise_audit_ledger_archive
                FOR EACH STATEMENT EXECUTE FUNCTION prevent_audit_ledger_archive_mutation();
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_audit_ledger_archive');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_audit_ledger_archive_mutation();');
        }
    }
};
