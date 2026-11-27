<?php

declare(strict_types=1);

namespace Modules\Distribution\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\Distribution\Domain\Enums\BucketType;
use Modules\Distribution\Models\ReplenishmentRule;
use Modules\Distribution\Services\DrpPlanner;
use Modules\Distribution\Services\ReplenishmentEngine;

/**
 * drp:run — recalculates the time-phased DRP (and, with --proposals, the replenishment and balancing proposals) of one company or of
 * every company that has replenishment rules. The scheduler runs the same work every night (RunDistributionPlanningJob).
 */
final class RunDrpCommand extends Command
{
    protected $signature = 'drp:run {--tenant= : Company id (all companies when omitted)} {--bucket=weekly : daily or weekly} {--horizon=8 : Number of time buckets} {--proposals : Also generate replenishment and balancing proposals}';

    protected $description = 'Recalculate the distribution requirements plan (DRP) and store the snapshot with its alerts';

    public function handle(DrpPlanner $planner, ReplenishmentEngine $engine, TenantContext $context): int
    {
        $bucket = BucketType::tryFrom((string) $this->option('bucket'));

        if ($bucket === null) {
            $this->error('The bucket must be daily or weekly.');

            return self::INVALID;
        }

        $tenants = Tenant::query()->when($this->option('tenant'), fn ($query, $id) => $query->whereKey($id))->get();
        $runs = 0;

        foreach ($tenants as $tenant) {
            $context->runWithTenant($tenant, function () use ($planner, $engine, $bucket, &$runs): void {
                if (! ReplenishmentRule::query()->where('is_active', true)->exists()) {
                    return;
                }

                $run = $planner->run($bucket, (int) $this->option('horizon'));
                $this->line($run->run_number.': '.(int) ($run->summary['rows'] ?? 0).' rows, '.(int) ($run->summary['alerts'] ?? 0).' alerts');

                if ($this->option('proposals')) {
                    $result = $engine->generate([], [], $run->id);
                    $this->line("  proposals: {$result['created']} replenishment, {$result['balancing']} balancing, {$result['shortfalls']} shortfalls");
                }

                $runs++;
            });
        }

        $this->info("DRP runs: {$runs}");

        return self::SUCCESS;
    }
}
