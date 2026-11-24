<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Services\ResidualHoursReportService;

final class SendResidualHoursReportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $contractId, public readonly string $tenantId) {}

    public function handle(TenantContext $tenantContext, ResidualHoursReportService $reports): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $tenantContext->setTenant($tenant);

        $contract = ServiceContract::query()->find($this->contractId);
        if ($contract === null) {
            return;
        }

        $reports->send($contract);
    }
}
