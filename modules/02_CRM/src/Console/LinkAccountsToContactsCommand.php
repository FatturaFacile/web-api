<?php

declare(strict_types=1);

namespace Modules\CRM\Console;

use Illuminate\Console\Command;
use Modules\CRM\Services\PartyMaster\AccountContactBackfill;

/**
 * Re-runs the account-to-contact consolidation (the same backfill the migration ran), for accounts imported or
 * created without a contact afterwards. Idempotent: linked accounts are skipped, decisions are upserted.
 */
final class LinkAccountsToContactsCommand extends Command
{
    protected $signature = 'crm:link-accounts-to-contacts {--tenant= : Only this tenant id}';

    protected $description = 'Link CRM accounts without a contact to the contact master (ambiguous cases are logged, not guessed)';

    public function handle(AccountContactBackfill $backfill): int
    {
        $tenant = $this->option('tenant');
        $stats = $backfill->run(is_string($tenant) && $tenant !== '' ? $tenant : null);

        $this->info(sprintf('Linked: %d, created: %d, ambiguous: %d', $stats['linked'], $stats['created'], $stats['ambiguous']));

        return self::SUCCESS;
    }
}
