<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Accounting\LegacyLedgerMigrator;
use Illuminate\Console\Command;

/**
 * Re-runs the copy of the legacy ledger entries into the journal of the General Ledger module, e.g. after a missing
 * fiscal year has been opened, and prints the counts. Idempotent: entries already copied are not posted again.
 */
class MigrateLegacyLedgerEntries extends Command
{
    protected $signature = 'ledger:migrate-legacy-entries';

    protected $description = 'Copy the legacy general_ledger_entries not yet in journal_entries into the General Ledger journal (idempotent; closed periods are skipped and logged)';

    /**
     * Runs the migrator and reports how many entries were copied, already present, or skipped per reason.
     */
    public function handle(LegacyLedgerMigrator $migrator): int
    {
        $counts = $migrator->migrate();

        $this->info("Migrated: {$counts['migrated']}, already migrated: {$counts['already_migrated']}");

        foreach ($counts['skipped'] as $reason => $count) {
            $this->warn("Skipped ({$reason}): {$count}");
        }

        return self::SUCCESS;
    }
}
