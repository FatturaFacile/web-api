<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\GeneralLedger\Services\FunctionalCurrencyBackfill;

/**
 * Reports what keeps the ledger of a tenant from being entirely expressed in its accounting currency:
 *
 * - the journal lines in a foreign currency that have no functional amounts, because they were posted without a usable
 *   exchange rate (the backfill never guesses one): until they are corrected with an entry, the reports read them at
 *   the amount as booked;
 * - the posted entries whose lines do not balance in the accounting currency (rounding of the per-line conversion of
 *   the history, or the lines above).
 *
 * With --backfill it first fills again what can be derived (idempotent, the same as the migration). It writes nothing
 * else. The exit code is 1 when anything is reported, so it can guard a deployment or a period close.
 */
final class FunctionalCurrencyAuditCommand extends Command
{
    protected $signature = 'general-ledger:functional-currency-audit
        {--tenant= : Limit the audit to one tenant}
        {--backfill : Fill the functional amounts that can be derived before reporting}';

    protected $description = 'Lists the journal lines without accounting-currency amounts and the entries that do not balance in the accounting currency';

    public function handle(FunctionalCurrencyBackfill $backfill): int
    {
        $tenantId = $this->option('tenant') !== null && $this->option('tenant') !== '' ? (string) $this->option('tenant') : null;

        if ((bool) $this->option('backfill')) {
            $filled = $backfill->run($tenantId);
            $this->info("Backfill: {$filled['accounting_currency']} accounting-currency line(s) and {$filled['foreign_currency']} foreign-currency line(s) filled.");
        }

        $lines = $backfill->unresolved($tenantId);
        $entries = $backfill->unbalancedEntries($tenantId);

        if ($lines->isEmpty() && $entries->isEmpty()) {
            $this->info('Every journal line has its amounts in the accounting currency and every posted entry balances in it.');

            return self::SUCCESS;
        }

        if ($lines->isNotEmpty()) {
            $this->warn("{$lines->count()} journal line(s) in a foreign currency without a usable exchange rate: correct them with an entry.");
            $this->table(
                ['Tenant', 'Entry', 'Date', 'Status', 'Line', 'Account', 'Currency', 'Debit', 'Credit', 'Rate'],
                $lines->map(static fn (object $line): array => [
                    $line->tenant_id,
                    $line->entry_number,
                    CarbonImmutable::parse($line->posting_date)->toDateString(),
                    $line->status,
                    $line->line_number,
                    $line->account_code,
                    $line->currency,
                    $line->debit_amount,
                    $line->credit_amount,
                    $line->exchange_rate,
                ])->all()
            );
        }

        if ($entries->isNotEmpty()) {
            $this->warn("{$entries->count()} posted entr(y/ies) not balanced in the accounting currency.");
            $this->table(
                ['Tenant', 'Entry', 'Date', 'Functional debit', 'Functional credit', 'Difference'],
                $entries->map(static fn (object $entry): array => [
                    $entry->tenant_id,
                    $entry->entry_number,
                    CarbonImmutable::parse($entry->posting_date)->toDateString(),
                    $entry->functional_debit,
                    $entry->functional_credit,
                    $entry->difference,
                ])->all()
            );
        }

        return self::FAILURE;
    }
}
