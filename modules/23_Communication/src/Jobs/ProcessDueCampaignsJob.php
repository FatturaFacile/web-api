<?php

declare(strict_types=1);

namespace Modules\Communication\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Communication\Services\CampaignService;

/**
 * Queued job: starts the scheduled campaigns of one company whose time has come.
 */
final class ProcessDueCampaignsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $tenantId)
    {
        $this->afterCommit();
    }

    /**
     * Run the unit of work in the context of the company.
     */
    public function handle(CampaignService $campaigns, TenantContext $context): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $context->runFor($tenant, static fn (): int => $campaigns->processDue());
    }
}
