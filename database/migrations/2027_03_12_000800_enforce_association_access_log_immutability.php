<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The access log can only grow. There is no exception: a retention purge, if a company ever needs one, has to be
     * added here with its own explicit rule.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_assoc_access_log_mutation()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'Association access log entries are strictly immutable and cannot be updated or deleted.';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_prevent_assoc_access_log_mutation ON assoc_access_logs;
            CREATE TRIGGER trg_prevent_assoc_access_log_mutation
            BEFORE UPDATE OR DELETE ON assoc_access_logs
            FOR EACH ROW EXECUTE FUNCTION prevent_assoc_access_log_mutation();

            DROP TRIGGER IF EXISTS trg_prevent_assoc_access_log_truncate ON assoc_access_logs;
            CREATE TRIGGER trg_prevent_assoc_access_log_truncate
            BEFORE TRUNCATE ON assoc_access_logs
            FOR EACH STATEMENT EXECUTE FUNCTION prevent_assoc_access_log_mutation();
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('
            DROP TRIGGER IF EXISTS trg_prevent_assoc_access_log_truncate ON assoc_access_logs;
            DROP TRIGGER IF EXISTS trg_prevent_assoc_access_log_mutation ON assoc_access_logs;
            DROP FUNCTION IF EXISTS prevent_assoc_access_log_mutation();
        ');
    }
};
