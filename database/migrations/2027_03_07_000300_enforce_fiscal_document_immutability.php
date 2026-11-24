<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * An issued invoice and a posted entry are corrected with a credit note or a reversal, never rewritten. The rule
     * lives in the database because two models share `invoices`. Each trigger lists what may still move: on an invoice
     * its collection state and the non-fiscal notes, on an entry only the step to reversed, on a line its clearing.
     * A restore runs with session_replication_role = replica, which does not fire these triggers.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_issued_invoice_mutation()
            RETURNS TRIGGER AS $$
            DECLARE
                mutable_columns text[] := ARRAY['payment_status', 'total_paid', 'receivable_amount', 'due_date', 'notes', 'payment_method', 'payment_terms', 'bank_name', 'bank_iban', 'gl_journal_entry_id', 'workflow_instance_id', 'updated_at'];
            BEGIN
                IF OLD.status <> 'posted' THEN
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;

                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE' AND (to_jsonb(NEW) - mutable_columns) = (to_jsonb(OLD) - mutable_columns) THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'An issued invoice is immutable: correct it with a credit note.';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_prevent_issued_invoice_mutation ON invoices;
            CREATE TRIGGER trg_prevent_issued_invoice_mutation
            BEFORE UPDATE OR DELETE ON invoices
            FOR EACH ROW EXECUTE FUNCTION prevent_issued_invoice_mutation();

            CREATE OR REPLACE FUNCTION prevent_issued_invoice_item_mutation()
            RETURNS TRIGGER AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM invoices WHERE id = OLD.invoice_id AND status = 'posted') THEN
                    RAISE EXCEPTION 'The lines of an issued invoice are immutable.';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_prevent_issued_invoice_item_mutation ON invoice_items;
            CREATE TRIGGER trg_prevent_issued_invoice_item_mutation
            BEFORE UPDATE OR DELETE ON invoice_items
            FOR EACH ROW EXECUTE FUNCTION prevent_issued_invoice_item_mutation();

            CREATE OR REPLACE FUNCTION prevent_posted_journal_mutation()
            RETURNS TRIGGER AS $$
            DECLARE
                reversal_columns text[] := ARRAY['status', 'reversal_date', 'reversal_journal_entry_id', 'reversed_at', 'reversed_by', 'updated_at'];
            BEGIN
                IF OLD.status NOT IN ('posted', 'reversed') THEN
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;

                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.status = 'posted' AND NEW.status IN ('posted', 'reversed')
                    AND (to_jsonb(NEW) - reversal_columns) = (to_jsonb(OLD) - reversal_columns) THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'A posted journal entry is immutable: reverse it.';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_prevent_posted_journal_mutation ON journal_entries;
            CREATE TRIGGER trg_prevent_posted_journal_mutation
            BEFORE UPDATE OR DELETE ON journal_entries
            FOR EACH ROW EXECUTE FUNCTION prevent_posted_journal_mutation();

            CREATE OR REPLACE FUNCTION prevent_posted_journal_line_mutation()
            RETURNS TRIGGER AS $$
            DECLARE
                clearing_columns text[] := ARRAY['is_cleared', 'clearing_reference', 'updated_at'];
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM journal_entries WHERE id = OLD.journal_entry_id AND status IN ('posted', 'reversed')) THEN
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;

                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE' AND (to_jsonb(NEW) - clearing_columns) = (to_jsonb(OLD) - clearing_columns) THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'The lines of a posted journal entry are immutable.';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_prevent_posted_journal_line_mutation ON journal_lines;
            CREATE TRIGGER trg_prevent_posted_journal_line_mutation
            BEFORE UPDATE OR DELETE ON journal_lines
            FOR EACH ROW EXECUTE FUNCTION prevent_posted_journal_line_mutation();
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('
            DROP TRIGGER IF EXISTS trg_prevent_posted_journal_line_mutation ON journal_lines;
            DROP FUNCTION IF EXISTS prevent_posted_journal_line_mutation();
            DROP TRIGGER IF EXISTS trg_prevent_posted_journal_mutation ON journal_entries;
            DROP FUNCTION IF EXISTS prevent_posted_journal_mutation();
            DROP TRIGGER IF EXISTS trg_prevent_issued_invoice_item_mutation ON invoice_items;
            DROP FUNCTION IF EXISTS prevent_issued_invoice_item_mutation();
            DROP TRIGGER IF EXISTS trg_prevent_issued_invoice_mutation ON invoices;
            DROP FUNCTION IF EXISTS prevent_issued_invoice_mutation();
        ');
    }
};
