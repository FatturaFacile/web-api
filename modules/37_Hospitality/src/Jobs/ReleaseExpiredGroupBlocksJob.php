<?php

declare(strict_types=1);

namespace Modules\Hospitality\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Hospitality\Services\GroupBookingService;

/**
 * Marks as released every open group block whose release date has passed, so the unpicked rooms are back on sale.
 */
final class ReleaseExpiredGroupBlocksJob implements ShouldQueue
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

        $released = $tenantContext->runWithTenant($tenant, static fn (): int => app(GroupBookingService::class)->releaseExpired());

        if ($released > 0) {
            Log::info('Expired group blocks released.', ['tenant_id' => $this->tenantId, 'released' => $released]);
        }
    }
}
