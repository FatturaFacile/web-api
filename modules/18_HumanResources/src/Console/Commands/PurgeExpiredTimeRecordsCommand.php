<?php

declare(strict_types=1);

namespace Modules\HumanResources\Console\Commands;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantPreferences;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\HumanResources\Services\TimeRetentionService;
use Modules\HumanResources\Services\TimeTracking\DataRetentionPolicy;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeRules;
use Throwable;

final class PurgeExpiredTimeRecordsCommand extends Command
{
    protected $signature = 'time:purge-expired';

    protected $description = 'Delete the working time records past their retention period for every tenant (at least five whole calendar years)';

    public function handle(TenantContext $tenantContext, TenantPreferences $preferences, TimeRetentionService $retention): int
    {
        $today = CarbonImmutable::now(SwissWorkingTimeRules::TIMEZONE);
        $hasFailed = false;

        Tenant::query()->each(function (Tenant $tenant) use ($tenantContext, $preferences, $retention, $today, &$hasFailed): void {
            // A restore from backup is replacing the data of this tenant: the next run will take care of it.
            if ($tenant->is_maintenance) {
                $this->warn("Tenant {$tenant->id} skipped: under maintenance.");

                return;
            }

            try {
                $tenantContext->runWithTenant($tenant, function () use ($tenant, $preferences, $retention, $today): void {
                    $years = (int) $preferences->get('time_tracking', 'retention_years', DataRetentionPolicy::MINIMUM_YEARS);
                    $result = $retention->purge($tenant, $today, $years);

                    $this->info(sprintf(
                        'Tenant %s: cutoff %s, %d clock events, %d daily records, %d audit entries.',
                        $tenant->id,
                        $result->cutoff->format('Y-m-d'),
                        $result->clockEvents,
                        $result->dailyRecords,
                        $result->auditEntries,
                    ));
                });
            } catch (Throwable $exception) {
                report($exception);
                $this->error("Tenant {$tenant->id} failed: {$exception->getMessage()}");
                $hasFailed = true;
            }
        });

        return $hasFailed ? self::FAILURE : self::SUCCESS;
    }
}
