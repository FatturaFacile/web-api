<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A settlement that reached the ledger can no longer change. The only step left is posted to paid, which touches
     * the payment columns alone. The rule lives in the database because the legacy model shares this table.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_vat_settlement_mutation()
            RETURNS TRIGGER AS $$
            DECLARE
                payment_columns text[] := ARRAY['status', 'is_paid', 'payment_date', 'paid_at', 'paid_by', 'payment_journal_id', 'updated_at'];
            BEGIN
                IF OLD.status NOT IN ('posted', 'paid') THEN
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;

                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.status = 'posted' AND NEW.status IN ('posted', 'paid')
                    AND (to_jsonb(NEW) - payment_columns) = (to_jsonb(OLD) - payment_columns) THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'A posted VAT settlement is immutable.';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_prevent_vat_settlement_mutation ON vat_settlements;
            CREATE TRIGGER trg_prevent_vat_settlement_mutation
            BEFORE UPDATE OR DELETE ON vat_settlements
            FOR EACH ROW EXECUTE FUNCTION prevent_vat_settlement_mutation();
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('
            DROP TRIGGER IF EXISTS trg_prevent_vat_settlement_mutation ON vat_settlements;
            DROP FUNCTION IF EXISTS prevent_vat_settlement_mutation();
        ');
    }
};
