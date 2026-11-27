<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\SwissEbill\Application\Services\EbillStatusPoller;
use Modules\SwissEbill\Models\EbillSetting;

/**
 * Fallback reconciliation of the eBill statuses for companies whose firewall delays or blocks the inbound webhooks. Safe to repeat.
 */
final class PollPendingEbillStatusesCommand extends Command
{
    protected $signature = 'ebill:poll-pending-statuses {--tenant=* : Limit to these tenants}';

    protected $description = 'Polls SIX Paynet / PostFinance for the status of the eBills still in flight';

    public function handle(TenantContext $tenantContext, EbillStatusPoller $poller): int
    {
        $enabled = EbillSetting::withoutGlobalScopes()->where('ebill_enabled', true)->whereNull('deleted_at')->pluck('tenant_id')->filter()->all();

        $tenants = Tenant::query()
            ->where('status', 'active')
            ->where('is_maintenance', false)
            ->whereIn('id', $enabled)
            ->when($this->option('tenant'), fn ($query, $ids) => $query->whereIn('id', $ids))
            ->get();

        foreach ($tenants as $tenant) {
            $result = $tenantContext->runWithTenant($tenant, fn (): array => $poller->pollCurrentTenant());
            $this->line("{$tenant->id}: {$result['polled']} polled, {$result['applied']} applied, {$result['failed']} unreachable, {$result['redispatched']} re-queued");
        }

        return self::SUCCESS;
    }
}
