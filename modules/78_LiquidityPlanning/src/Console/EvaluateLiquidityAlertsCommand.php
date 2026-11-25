<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\LiquidityPlanning\Services\AlertService;

/**
 * Evaluates the liquidity early-warning conditions of every tenant (daily): raises the alerts, notifies the dashboard and the
 * mailboxes within the cooldown window and resolves the conditions that ended.
 */
final class EvaluateLiquidityAlertsCommand extends Command
{
    protected $signature = 'liquidity:evaluate-alerts {--tenant= : Evaluate one tenant only}';

    protected $description = 'Evaluate the liquidity early-warning alerts (negative balance, DSCR, capital loss, leverage, covenants)';

    public function handle(AlertService $alerts, TenantContext $context): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), static fn ($query, string $id) => $query->whereKey($id))->get();
        $raised = 0;

        foreach ($tenants as $tenant) {
            $raised += count($context->runWithTenant($tenant, static fn (): array => $alerts->evaluate($tenant)));
        }

        $this->info($tenants->count().' tenant(s) evaluated, '.$raised.' open alert(s).');

        return self::SUCCESS;
    }
}
