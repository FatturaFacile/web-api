<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A closed run can never be changed, whatever the code that tries: the models refuse it first, these triggers are
     * the last line of defence. The only way back is the explicit reopening, which raises `app.payroll_reopen` inside
     * its own transaction and leaves a row in the history; the history itself is append-only. Tenant restore runs with
     * session_replication_role = replica, so it does not fire them.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared("
            CREATE OR REPLACE FUNCTION payroll_protect_closed_run()
            RETURNS TRIGGER AS $$
            BEGIN
                IF OLD.status = 'Final' THEN
                    IF TG_OP = 'UPDATE' AND NEW.status = 'Draft' AND current_setting('app.payroll_reopen', true) = 'on' THEN
                        RETURN NEW;
                    END IF;

                    RAISE EXCEPTION 'Closed payroll runs are immutable: reopen the run first.';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION payroll_protect_closed_payslip()
            RETURNS TRIGGER AS $$
            DECLARE
                run_id uuid;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    run_id := OLD.payroll_run_id;
                ELSE
                    run_id := NEW.payroll_run_id;
                END IF;

                IF EXISTS (SELECT 1 FROM payroll_runs WHERE id = run_id AND status = 'Final') THEN
                    RAISE EXCEPTION 'Payslips of closed payroll runs are immutable: reopen the run first.';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION payroll_protect_closed_payslip_line()
            RETURNS TRIGGER AS $$
            DECLARE
                slip_id uuid;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    slip_id := OLD.payslip_id;
                ELSE
                    slip_id := NEW.payslip_id;
                END IF;

                IF EXISTS (
                    SELECT 1 FROM payroll_payslips p
                    JOIN payroll_runs r ON r.id = p.payroll_run_id
                    WHERE p.id = slip_id AND r.status = 'Final'
                ) THEN
                    RAISE EXCEPTION 'Payslips of closed payroll runs are immutable: reopen the run first.';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION payroll_prevent_history_mutation()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'The history of a payroll run cannot be updated or deleted.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_payroll_protect_closed_run
            BEFORE UPDATE OR DELETE ON payroll_runs
            FOR EACH ROW EXECUTE FUNCTION payroll_protect_closed_run();

            CREATE TRIGGER trg_payroll_protect_closed_payslip
            BEFORE INSERT OR UPDATE OR DELETE ON payroll_payslips
            FOR EACH ROW EXECUTE FUNCTION payroll_protect_closed_payslip();

            CREATE TRIGGER trg_payroll_protect_closed_payslip_line
            BEFORE INSERT OR UPDATE OR DELETE ON payroll_payslip_lines
            FOR EACH ROW EXECUTE FUNCTION payroll_protect_closed_payslip_line();

            CREATE TRIGGER trg_payroll_prevent_history_mutation
            BEFORE UPDATE OR DELETE ON payroll_run_events
            FOR EACH ROW EXECUTE FUNCTION payroll_prevent_history_mutation();

            CREATE TRIGGER trg_payroll_prevent_history_truncate
            BEFORE TRUNCATE ON payroll_run_events
            FOR EACH STATEMENT EXECUTE FUNCTION payroll_prevent_history_mutation();
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('
            DROP TRIGGER IF EXISTS trg_payroll_prevent_history_truncate ON payroll_run_events;
            DROP TRIGGER IF EXISTS trg_payroll_prevent_history_mutation ON payroll_run_events;
            DROP TRIGGER IF EXISTS trg_payroll_protect_closed_payslip_line ON payroll_payslip_lines;
            DROP TRIGGER IF EXISTS trg_payroll_protect_closed_payslip ON payroll_payslips;
            DROP TRIGGER IF EXISTS trg_payroll_protect_closed_run ON payroll_runs;
            DROP FUNCTION IF EXISTS payroll_prevent_history_mutation();
            DROP FUNCTION IF EXISTS payroll_protect_closed_payslip_line();
            DROP FUNCTION IF EXISTS payroll_protect_closed_payslip();
            DROP FUNCTION IF EXISTS payroll_protect_closed_run();
        ');
    }
};
