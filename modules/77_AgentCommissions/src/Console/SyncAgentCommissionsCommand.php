<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\AgentCommissions\Services\CommissionAccrualService;

/**
 * Accrues the commissions of the invoices of a period and re-reads the collections and bank reconciliations of the
 * commissions that are still locked, for every active company. Safe to run at any time (idempotent); it is a safety net
 * for payments reconciled by a path that raised no event.
 */
final class SyncAgentCommissionsCommand extends Command
{
    protected $signature = 'agent-commissions:sync {--from= : First invoice date (Y-m-d)} {--to= : Last invoice date (Y-m-d)} {--tenant=* : Limit to these tenants}';

    protected $description = 'Synchronises the agent commission ledger with invoices, collections and bank reconciliations';

    public function handle(TenantContext $tenantContext, CommissionAccrualService $accruals): int
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $tenants = Tenant::query()
            ->where('status', 'active')
            ->where('is_maintenance', false)
            ->when($this->option('tenant'), fn ($query, $ids) => $query->whereIn('id', $ids))
            ->get();

        foreach ($tenants as $tenant) {
            $tenantContext->runWithTenant($tenant, function () use ($tenant, $accruals, $from, $to): void {
                $period = is_string($from) && is_string($to) ? $accruals->syncPeriod($from, $to) : 0;
                $open = $accruals->syncOpen();
                $this->line("{$tenant->id}: {$period} invoices of the period, {$open} open invoices synchronised");
            });
        }

        return self::SUCCESS;
    }
}
