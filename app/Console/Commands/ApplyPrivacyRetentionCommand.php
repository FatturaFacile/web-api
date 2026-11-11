<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Privacy\PrivacyRetentionService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Applies the privacy retention policies of every company (or of one): rows past their retention period are
 * anonymised. With --dry-run nothing is changed and the rows that would be anonymised are only counted.
 */
final class ApplyPrivacyRetentionCommand extends Command
{
    protected $signature = 'privacy:apply-retention {--tenant= : Id of a single company} {--dry-run : Count the rows without anonymising them}';

    protected $description = 'Anonymise personal data past the retention period of the privacy policies of each tenant (use --dry-run to only count)';

    public function handle(PrivacyRetentionService $retention): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenantOption = $this->option('tenant');
        $hasFailed = false;

        Tenant::query()
            ->when(is_string($tenantOption) && $tenantOption !== '', static fn ($query) => $query->whereKey($tenantOption))
            ->each(function (Tenant $tenant) use ($retention, $dryRun, &$hasFailed): void {
                // A restore from backup is replacing the data of this tenant: the next run will take care of it.
                if ($tenant->is_maintenance) {
                    $this->warn("Tenant {$tenant->id} skipped: under maintenance.");

                    return;
                }

                try {
                    foreach ($retention->runForTenant((string) $tenant->id, $dryRun) as $run) {
                        if ($run->status === 'failed') {
                            $hasFailed = true;
                        }

                        $this->info(sprintf(
                            'Tenant %s, %s: %s, %d matched, %d anonymised%s.',
                            $tenant->id,
                            $run->entry_key,
                            $run->status,
                            $run->matched_count,
                            $run->processed_count,
                            $dryRun ? ' (dry run)' : '',
                        ));
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $this->error("Tenant {$tenant->id} failed: {$exception->getMessage()}");
                    $hasFailed = true;
                }
            });

        return $hasFailed ? self::FAILURE : self::SUCCESS;
    }
}
