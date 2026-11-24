<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The history of the working time records can never be changed. The only deletion allowed is the retention purge,
     * which raises `app.time_audit_purge` inside its own transaction, and even then only for rows older than the five
     * whole calendar years the law requires (art. 73 cpv. 2 OLL 1).
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_time_audit_mutation()
            RETURNS TRIGGER AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND current_setting('app.time_audit_purge', true) = 'on' THEN
                    IF (OLD.occurred_at AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Zurich')
                        < date_trunc('year', now() AT TIME ZONE 'Europe/Zurich') - interval '5 years' THEN
                        RETURN OLD;
                    END IF;
                END IF;

                RAISE EXCEPTION 'Time audit entries are strictly immutable and cannot be updated or deleted.';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_prevent_time_audit_mutation ON time_audit_entries;
            CREATE TRIGGER trg_prevent_time_audit_mutation
            BEFORE UPDATE OR DELETE ON time_audit_entries
            FOR EACH ROW EXECUTE FUNCTION prevent_time_audit_mutation();

            DROP TRIGGER IF EXISTS trg_prevent_time_audit_truncate ON time_audit_entries;
            CREATE TRIGGER trg_prevent_time_audit_truncate
            BEFORE TRUNCATE ON time_audit_entries
            FOR EACH STATEMENT EXECUTE FUNCTION prevent_time_audit_mutation();
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('
            DROP TRIGGER IF EXISTS trg_prevent_time_audit_truncate ON time_audit_entries;
            DROP TRIGGER IF EXISTS trg_prevent_time_audit_mutation ON time_audit_entries;
            DROP FUNCTION IF EXISTS prevent_time_audit_mutation();
        ');
    }
};
