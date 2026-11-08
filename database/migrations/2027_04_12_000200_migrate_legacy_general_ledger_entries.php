<?php

declare(strict_types=1);

use App\Services\Accounting\LegacyLedgerMigrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Data only: copies the legacy ledger entries not yet in `journal_entries` into the journal of the General Ledger
 * module (see App\Services\Accounting\LegacyLedgerMigrator for the rules). Idempotent, so running it again, or running
 * `php artisan ledger:migrate-legacy-entries` afterwards, copies only what is still missing; entries of closed periods
 * are logged in `legacy_ledger_migration_log` and skipped. The legacy rows are never changed or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $counts = app(LegacyLedgerMigrator::class)->migrate();

        Log::info('Legacy general ledger entries migrated to journal_entries', $counts);
    }

    /**
     * Not reversed: posted journal entries are immutable and are corrected by reversal, not deleted.
     */
    public function down(): void {}
};
