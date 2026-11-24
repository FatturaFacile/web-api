<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One depreciation run per company, period and ledger. Installations whose `eam_depreciation_runs` table was created
 * before its unique index existed (the create migration skips an existing table) get it now.
 *
 * Runs that already doubled a period are not deleted: their schedules and journal entries refer to them. The first run
 * of the period stays; the later ones are soft deleted and marked in `summary_metrics.duplicate_of`, and the index
 * covers the live runs only, which is also what the engine checks before starting a run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || $this->hasPeriodIndex()) {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE eam_depreciation_runs AS run
            SET deleted_at = NOW(),
                summary_metrics = COALESCE(run.summary_metrics, '{}'::jsonb) || jsonb_build_object('duplicate_of', ranked.keeper_id)
            FROM (
                SELECT id, FIRST_VALUE(id) OVER period AS keeper_id
                FROM eam_depreciation_runs
                WHERE deleted_at IS NULL
                WINDOW period AS (PARTITION BY tenant_id, period_code, period_type, ledger_type ORDER BY executed_at NULLS LAST, created_at, id)
            ) AS ranked
            WHERE run.id = ranked.id AND ranked.id <> ranked.keeper_id
            SQL);

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS eam_depr_runs_live_period_unique ON eam_depreciation_runs (tenant_id, period_code, period_type, ledger_type) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS eam_depr_runs_live_period_unique');
    }

    /**
     * Whether a unique index already guards the period (the one of the create migration, or this one).
     */
    private function hasPeriodIndex(): bool
    {
        return DB::table('pg_indexes')
            ->whereRaw('schemaname = current_schema()')
            ->where('tablename', 'eam_depreciation_runs')
            ->whereIn('indexname', ['eam_depr_runs_period_unique', 'eam_depr_runs_live_period_unique'])
            ->exists();
    }
};
