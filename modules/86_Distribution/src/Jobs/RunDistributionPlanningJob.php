<?php

declare(strict_types=1);

namespace Modules\Distribution\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Distribution\Domain\Enums\BucketType;
use Modules\Distribution\Models\ReplenishmentRule;
use Modules\Distribution\Services\DrpPlanner;
use Modules\Distribution\Services\ReplenishmentEngine;

/**
 * Nightly planning of one company: the weekly DRP over eight weeks and the replenishment and balancing proposals of that run.
 */
final class RunDistributionPlanningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $tenantId) {}

    public function handle(DrpPlanner $planner, ReplenishmentEngine $engine, TenantContext $context): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        $context->runWithTenant($tenant, function () use ($planner, $engine): void {
            if (! ReplenishmentRule::query()->where('is_active', true)->exists()) {
                return;
            }

            $run = $planner->run(BucketType::Weekly, 8);
            $engine->generate([], [], $run->id);
        });
    }
}
