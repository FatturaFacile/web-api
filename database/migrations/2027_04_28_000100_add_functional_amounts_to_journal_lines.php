<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every ledger balance and report is expressed in the accounting (functional) currency of the tenant. A line keeps
     * its transaction-currency amounts (`debit_amount`, `credit_amount`, `currency`, `exchange_rate`) and now carries
     * its debit and credit in the accounting currency too: the single unsigned `amount_in_functional_currency` could not
     * say on which side it sits, nor be summed per side.
     *
     * The columns are nullable: a line posted before this migration gets its values from the backfill that follows,
     * and a foreign-currency line without a usable rate stays null (it is listed by
     * `general-ledger:functional-currency-audit`, never guessed).
     *
     * A posted line is immutable (trigger `prevent_posted_journal_line_mutation`). The trigger now also lets the two
     * functional columns be written once, from null, and nothing else with them: that is how the backfill fills the
     * history without opening the lines to any other change.
     */
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('journal_lines', 'functional_debit')) {
                $table->decimal('functional_debit', 15, 4)->nullable();
            }

            if (! Schema::hasColumn('journal_lines', 'functional_credit')) {
                $table->decimal('functional_credit', 15, 4)->nullable();
            }
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_posted_journal_line_mutation()
            RETURNS TRIGGER AS $$
            DECLARE
                clearing_columns text[] := ARRAY['is_cleared', 'clearing_reference', 'updated_at'];
                backfill_columns text[] := ARRAY['is_cleared', 'clearing_reference', 'updated_at', 'functional_debit', 'functional_credit'];
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

                IF TG_OP = 'UPDATE' AND OLD.functional_debit IS NULL AND OLD.functional_credit IS NULL
                    AND (to_jsonb(NEW) - backfill_columns) = (to_jsonb(OLD) - backfill_columns) THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'The lines of a posted journal entry are immutable.';
            END;
            $$ LANGUAGE plpgsql;
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("
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
            ");
        }

        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->dropColumn(['functional_debit', 'functional_credit']);
        });
    }
};
