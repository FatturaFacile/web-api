<?php

declare(strict_types=1);

namespace Modules\Analytics\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Modules\Analytics\Services\KpiSnapshotService;

/**
 * Refreshes the daily KPI snapshots of every active company (the day that just ended on its own calendar, or the day
 * given with --date). Safe to run again: the snapshot of the day is replaced.
 */
final class RefreshKpiSnapshotsCommand extends Command
{
    protected $signature = 'analytics:refresh-kpi-snapshots {--tenant=* : Limit to these tenants} {--date= : Day to describe (Y-m-d), default yesterday}';

    protected $description = 'Materialises the daily snapshots of the heaviest KPIs for every company';

    public function handle(TenantContext $tenantContext, KpiSnapshotService $service): int
    {
        $failures = 0;
        $date = $this->option('date');
        $day = null;

        if (is_string($date) && $date !== '') {
            try {
                $day = CarbonImmutable::createFromFormat('!Y-m-d', $date);
            } catch (InvalidFormatException) {
                $day = null;
            }

            if (! $day instanceof CarbonImmutable || $day->toDateString() !== $date) {
                $this->error('--date must be a date in the form Y-m-d.');

                return self::FAILURE;
            }
        }

        $tenants = Tenant::query()
            ->where('status', 'active')
            ->where('is_maintenance', false)
            ->when($this->option('tenant'), fn ($query, $ids) => $query->whereIn('id', $ids))
            ->get();

        foreach ($tenants as $tenant) {
            $tenantContext->runWithTenant($tenant, function () use ($tenant, $service, $day, &$failures): void {
                $result = $service->refresh($day ?: null);
                $service->prune();
                $failures += count($result['failed']);
                $this->line("{$tenant->id}: {$result['stored']} snapshots".($result['failed'] === [] ? '' : ', failed: '.implode(', ', $result['failed'])));
            });
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
