<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Console;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Modules\FranchiseNetwork\Jobs\CalculateRoyaltiesJob;
use Modules\FranchiseNetwork\Jobs\MaintainFranchiseNetworkJob;
use Modules\FranchiseNetwork\Jobs\SnapshotBenchmarksJob;
use Modules\FranchiseNetwork\Models\FranchiseStore;

/**
 * Runs a scheduled task of the franchise network by hand, for every franchisor or the given ones: the monthly royalty run (with
 * --invoice also the invoices), the benchmark snapshot or the daily upkeep. Safe to repeat: a run already invoiced is left alone.
 */
final class RunFranchiseNetworkCommand extends Command
{
    protected $signature = 'franchise-network:run {task : royalties, benchmark or maintenance} {--period= : Month (YYYY-MM), default the month that just ended} {--invoice : Issue the invoices of the royalty run} {--tenant=* : Limit to these tenants}';

    protected $description = 'Runs the royalty run, the benchmark snapshot or the upkeep of the franchise networks';

    public function handle(): int
    {
        $task = (string) $this->argument('task');

        if (! in_array($task, ['royalties', 'benchmark', 'maintenance'], true)) {
            $this->error('Unknown task: '.$task);

            return self::FAILURE;
        }

        $franchisors = FranchiseStore::query()->withoutGlobalScopes()->select('tenant_id')->distinct()->pluck('tenant_id');
        $tenants = Tenant::query()
            ->whereIn('id', $franchisors)
            ->where('status', 'active')
            ->when($this->option('tenant'), fn ($query, $ids) => $query->whereIn('id', $ids))
            ->get();

        foreach ($tenants as $tenant) {
            match ($task) {
                'royalties' => CalculateRoyaltiesJob::dispatchSync((string) $tenant->id, $this->option('period') ?: null, (bool) $this->option('invoice')),
                'benchmark' => SnapshotBenchmarksJob::dispatchSync((string) $tenant->id, $this->option('period') ?: null),
                'maintenance' => MaintainFranchiseNetworkJob::dispatchSync((string) $tenant->id),
            };
            $this->line($tenant->id.': '.$task.' done');
        }

        return self::SUCCESS;
    }
}
