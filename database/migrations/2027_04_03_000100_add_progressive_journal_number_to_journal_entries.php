<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Progressive number of the libro giornale: per tenant and fiscal year, assigned when an entry is posted. The free
     * entry_number stays as it is for compatibility. Entries already posted are numbered once, in posting order; their
     * other columns are not touched.
     */
    public function up(): void
    {
        Schema::create('gl_journal_number_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('fiscal_year_id');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'fiscal_year_id'], 'uq_gl_journal_number_sequence');
        });

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->uuid('fiscal_year_id')->nullable();
            $table->unsignedInteger('journal_number')->nullable();

            $table->unique(['tenant_id', 'fiscal_year_id', 'journal_number'], 'uq_gl_journal_number');
            $table->index(['tenant_id', 'posting_date', 'journal_number'], 'idx_gl_journal_book');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropUnique('uq_gl_journal_number');
            $table->dropIndex('idx_gl_journal_book');
            $table->dropColumn(['fiscal_year_id', 'journal_number']);
        });

        Schema::dropIfExists('gl_journal_number_sequences');
    }

    private function backfill(): void
    {
        $isPostgres = DB::getDriverName() === 'pgsql';

        // The immutability trigger of posted entries would refuse the one-off numbering.
        if ($isPostgres) {
            DB::statement('ALTER TABLE journal_entries DISABLE TRIGGER trg_prevent_posted_journal_mutation');
        }

        try {
            $entries = DB::table('journal_entries')
                ->join('fiscal_periods', 'fiscal_periods.id', '=', 'journal_entries.fiscal_period_id')
                ->whereIn('journal_entries.status', ['posted', 'reversed'])
                ->orderBy('journal_entries.tenant_id')
                ->orderBy('fiscal_periods.fiscal_year_id')
                ->orderByRaw('COALESCE(journal_entries.posted_at, journal_entries.created_at)')
                ->orderBy('journal_entries.posting_date')
                ->orderBy('journal_entries.id')
                ->select(['journal_entries.id', 'journal_entries.tenant_id', 'fiscal_periods.fiscal_year_id'])
                ->cursor();

            $counters = [];

            foreach ($entries as $entry) {
                $key = $entry->tenant_id.'|'.$entry->fiscal_year_id;
                $counters[$key] = ($counters[$key] ?? 0) + 1;

                DB::table('journal_entries')->where('id', $entry->id)->update([
                    'fiscal_year_id' => $entry->fiscal_year_id,
                    'journal_number' => $counters[$key],
                ]);
            }

            foreach ($counters as $key => $lastNumber) {
                [$tenantId, $fiscalYearId] = explode('|', $key);

                DB::table('gl_journal_number_sequences')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'fiscal_year_id' => $fiscalYearId,
                    'last_number' => $lastNumber,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } finally {
            if ($isPostgres) {
                DB::statement('ALTER TABLE journal_entries ENABLE TRIGGER trg_prevent_posted_journal_mutation');
            }
        }
    }
};
