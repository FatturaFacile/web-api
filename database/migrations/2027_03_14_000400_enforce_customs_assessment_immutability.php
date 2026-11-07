<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The archive of the assessment decisions can only grow. Nothing may change or remove a row: the recipient is
     * bound by law to keep the signed decision, and the retention period is not encoded here, so no purge path exists.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_customs_assessment_mutation()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'Customs assessment decisions are strictly immutable and cannot be updated or deleted.';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_prevent_customs_assessment_mutation ON customs_assessment_decisions;
            CREATE TRIGGER trg_prevent_customs_assessment_mutation
            BEFORE UPDATE OR DELETE ON customs_assessment_decisions
            FOR EACH ROW EXECUTE FUNCTION prevent_customs_assessment_mutation();

            DROP TRIGGER IF EXISTS trg_prevent_customs_assessment_truncate ON customs_assessment_decisions;
            CREATE TRIGGER trg_prevent_customs_assessment_truncate
            BEFORE TRUNCATE ON customs_assessment_decisions
            FOR EACH STATEMENT EXECUTE FUNCTION prevent_customs_assessment_mutation();
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('
            DROP TRIGGER IF EXISTS trg_prevent_customs_assessment_truncate ON customs_assessment_decisions;
            DROP TRIGGER IF EXISTS trg_prevent_customs_assessment_mutation ON customs_assessment_decisions;
            DROP FUNCTION IF EXISTS prevent_customs_assessment_mutation();
        ');
    }
};
