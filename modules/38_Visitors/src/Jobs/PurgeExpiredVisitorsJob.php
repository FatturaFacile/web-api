<?php

declare(strict_types=1);

namespace Modules\Visitors\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Visitors\Services\VisitorService;

/**
 * Erases the visitors and visits of a company that reached the end of their retention period.
 */
final class PurgeExpiredVisitorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Inject the collaborators.
     */
    public function __construct(public readonly string $tenantId)
    {
        $this->afterCommit();
    }

    /**
     * Run the unit of work.
     */
    public function handle(TenantContext $tenantContext): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null || $tenant->is_maintenance) {
            return;
        }

        $erased = $tenantContext->runWithTenant($tenant, static fn (): array => app(VisitorService::class)->purgeExpired());

        if ($erased['visits'] > 0 || $erased['visitors'] > 0) {
            Log::info('Visitor data erased at the end of the retention period.', ['tenant_id' => $this->tenantId, ...$erased]);
        }
    }
}
