<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared("
                CREATE OR REPLACE FUNCTION prevent_audit_ledger_mutation()
                RETURNS TRIGGER AS $$
                BEGIN
                    RAISE EXCEPTION 'Audit ledger records are strictly immutable and cannot be updated or deleted.';
                END;
                $$ LANGUAGE plpgsql;

                DROP TRIGGER IF EXISTS trg_prevent_audit_ledger_mutation ON enterprise_audit_ledger;
                CREATE TRIGGER trg_prevent_audit_ledger_mutation
                BEFORE UPDATE OR DELETE ON enterprise_audit_ledger
                FOR EACH ROW EXECUTE FUNCTION prevent_audit_ledger_mutation();
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('
                DROP TRIGGER IF EXISTS trg_prevent_audit_ledger_mutation ON enterprise_audit_ledger;
                DROP FUNCTION IF EXISTS prevent_audit_ledger_mutation();
            ');
        }
    }
};
