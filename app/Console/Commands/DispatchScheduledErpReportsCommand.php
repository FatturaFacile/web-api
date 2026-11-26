<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Reports\EvaluateTenantScheduledReportsJob;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Tier 1 scheduler command: dispatches per-tenant evaluation jobs for scheduled ERP daily reports.
 */
final class DispatchScheduledErpReportsCommand extends Command
{
    protected $signature = 'reports:dispatch-scheduled {--tenant=* : Limit dispatch to specific tenant UUIDs}';

    protected $description = 'Dispatch scheduled ERP daily summary report evaluation jobs for active tenants';

    public function handle(): int
    {
        $evaluatedAtIso = Carbon::now('UTC')->toIso8601String();
        $dispatched = 0;

        Tenant::query()
            ->where('status', 'active')
            ->where('is_maintenance', false)
            ->when($this->option('tenant'), static fn ($query, array $ids) => $query->whereIn('id', $ids))
            ->select(['id'])
            ->chunkById(100, static function ($tenants) use ($evaluatedAtIso, &$dispatched): void {
                foreach ($tenants as $tenant) {
                    EvaluateTenantScheduledReportsJob::dispatch((string) $tenant->id, $evaluatedAtIso);
                    $dispatched++;
                }
            });

        $this->line("{$dispatched} tenant report evaluation jobs dispatched.");

        return self::SUCCESS;
    }
}
