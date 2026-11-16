<?php

declare(strict_types=1);

namespace Modules\Facility\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Facility\Services\InspectionService;

/**
 * Generates the recurring inspections that are due for a company. Running it twice creates nothing twice.
 */
final class GenerateFacilityInspectionsJob implements ShouldQueue
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

        $created = $tenantContext->runWithTenant($tenant, static fn (): int => app(InspectionService::class)->generateDue()->count());

        if ($created > 0) {
            Log::info('Facility inspections generated.', ['tenant_id' => $this->tenantId, 'created' => $created]);
        }
    }
}
