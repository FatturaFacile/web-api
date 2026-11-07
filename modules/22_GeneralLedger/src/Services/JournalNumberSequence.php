<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;

/**
 * Progressive number of the libro giornale, per tenant and fiscal year, without gaps among posted entries.
 *
 * The sequence row is locked for the rest of the posting transaction, so two concurrent postings of the same year
 * wait for each other instead of taking the same number; a rolled back posting gives its number back.
 */
final class JournalNumberSequence
{
    /**
     * Gives a posted entry its number when it has none yet. Entries without a fiscal period are left unnumbered.
     */
    public function assign(JournalEntry $entry): void
    {
        if ($entry->getAttribute('journal_number') !== null || empty($entry->getAttribute('fiscal_period_id'))) {
            return;
        }

        $fiscalYearId = FiscalPeriod::withoutGlobalScopes()->whereKey($entry->getAttribute('fiscal_period_id'))->value('fiscal_year_id');

        if ($fiscalYearId === null) {
            return;
        }

        $entry->setAttribute('fiscal_year_id', (string) $fiscalYearId);
        $entry->setAttribute('journal_number', $this->next((string) $entry->getAttribute('tenant_id'), (string) $fiscalYearId));
    }

    public function next(string $tenantId, string $fiscalYearId): int
    {
        return DB::transaction(function () use ($tenantId, $fiscalYearId): int {
            DB::table('gl_journal_number_sequences')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'fiscal_year_id' => $fiscalYearId,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DB::table('gl_journal_number_sequences')
                ->where('tenant_id', $tenantId)
                ->where('fiscal_year_id', $fiscalYearId)
                ->lockForUpdate()
                ->first(['id', 'last_number']);

            $number = (int) $sequence->last_number + 1;

            DB::table('gl_journal_number_sequences')->where('id', $sequence->id)->update([
                'last_number' => $number,
                'updated_at' => now(),
            ]);

            return $number;
        });
    }
}
