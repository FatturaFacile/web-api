<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Console;

use Illuminate\Console\Command;
use Modules\SubscriptionBilling\Services\LegacySubscriptionMigrator;

/**
 * Copies the legacy recurring-billing subscriptions still missing from the subscription engine (idempotent).
 */
final class MigrateLegacySubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:migrate-legacy';

    protected $description = 'Copy the legacy recurring-billing subscriptions still missing into the subscription engine (idempotent)';

    public function handle(LegacySubscriptionMigrator $migrator): int
    {
        $counts = $migrator->migrate();

        $this->info(sprintf(
            'Migrated: %d; already migrated: %d; skipped without tenant: %d; failed: %d',
            $counts['migrated'],
            $counts['already_migrated'],
            $counts['skipped_without_tenant'],
            $counts['failed']
        ));

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
