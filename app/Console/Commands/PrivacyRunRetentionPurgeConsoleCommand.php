<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Privacy\Governance\Commands\RunAutomatedRetentionPurgeCommand;
use App\Services\Privacy\Governance\Commands\RunAutomatedRetentionPurgeHandler;
use App\Services\Privacy\Governance\RetentionPurgeService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily purge of the Privacy Governance retention rules (module 096). Statutory locked modules are never touched.
 */
final class PrivacyRunRetentionPurgeConsoleCommand extends Command
{
    protected $signature = 'privacy:run-retention-purge {--tenant= : Id of a single tenant} {--dry-run : Count without anonymising}';

    protected $description = 'Anonymise expired candidates, leads and tickets per the privacy retention rules of each tenant (fiscal records are never touched)';

    public function handle(RetentionPurgeService $service, RunAutomatedRetentionPurgeHandler $handler): int
    {
        $only = $this->option('tenant');
        $failed = false;

        foreach ($service->tenantsWithRules() as $tenantId) {
            if (is_string($only) && $only !== '' && $only !== $tenantId) {
                continue;
            }

            try {
                $log = $handler->handle(new RunAutomatedRetentionPurgeCommand($tenantId, (bool) $this->option('dry-run')));
                $this->info(sprintf('Tenant %s: %d evaluated, %d purged, %d anonymised, %d statutory skipped.', $tenantId, $log->evaluated_count, $log->purged_count, $log->anonymized_count, $log->statutory_skipped_count));
            } catch (Throwable $e) {
                report($e);
                $this->error("Tenant {$tenantId} failed: {$e->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
