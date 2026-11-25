<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\TenderManagement\Services\AlertScanner;

/**
 * Hourly scan of the tender deadlines (7 days, 72 h, 24 h, 4 h) and of the qualification vault expirations.
 */
final class ScanTenderAlertsCommand extends Command
{
    protected $signature = 'tender:scan-alerts {--tenant= : Only this tenant id} {--at= : Run as of this moment (ISO 8601), default now}';

    protected $description = 'Raise the multi-level tender deadline and qualification vault expiry alerts';

    public function handle(AlertScanner $scanner, TenantContext $tenants): int
    {
        $now = $this->option('at') !== null ? CarbonImmutable::parse((string) $this->option('at')) : CarbonImmutable::now();
        $raised = 0;

        Tenant::query()->when($this->option('tenant') !== null, fn ($query) => $query->whereKey((string) $this->option('tenant')))
            ->each(function (Tenant $tenant) use ($scanner, $tenants, $now, &$raised): void {
                $raised += (int) $tenants->runWithTenant($tenant, fn (): int => $scanner->scan($now));
            });

        $this->info("Tender alerts raised: {$raised}");

        return self::SUCCESS;
    }
}
