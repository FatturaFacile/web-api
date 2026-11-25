<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\CustomerSatisfaction\Models\SurveyDispatch;
use Modules\CustomerSatisfaction\Services\SurveyDispatchProcessor;

/**
 * Sends the scheduled survey invitations that are due. Safe to repeat.
 */
final class ProcessSurveyDispatchesCommand extends Command
{
    protected $signature = 'surveys:process-dispatches {--tenant=* : Limit to these tenants}';

    protected $description = 'Sends the due NPS / CSAT / CES survey invitations';

    public function handle(TenantContext $tenantContext, SurveyDispatchProcessor $processor): int
    {
        $tenants = Tenant::query()
            ->where('status', 'active')
            ->where('is_maintenance', false)
            ->when($this->option('tenant'), fn ($query, $ids) => $query->whereIn('id', $ids))
            ->get();

        foreach ($tenants as $tenant) {
            $tenantContext->runWithTenant($tenant, function () use ($tenant, $processor): void {
                if (! SurveyDispatch::query()->where('status', 'scheduled')->exists()) {
                    return;
                }

                $result = $processor->processDue();
                $this->line("{$tenant->id}: {$result['sent']} sent, {$result['failed']} failed, {$result['suppressed']} suppressed");
            });
        }

        return self::SUCCESS;
    }
}
