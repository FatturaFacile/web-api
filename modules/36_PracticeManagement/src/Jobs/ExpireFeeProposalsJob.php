<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\PracticeManagement\Services\FeeProposalService;

/**
 * Marks as expired the sent fee proposals whose validity date has passed.
 */
final class ExpireFeeProposalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $tenantId)
    {
        $this->afterCommit();
    }

    public function handle(TenantContext $tenantContext): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null || $tenant->is_maintenance) {
            return;
        }

        $expired = $tenantContext->runWithTenant($tenant, static fn (): int => app(FeeProposalService::class)->expireOverdue());

        if ($expired > 0) {
            Log::info('Fee proposals expired.', ['tenant_id' => $this->tenantId, 'expired' => $expired]);
        }
    }
}
