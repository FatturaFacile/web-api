<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\CrisisMonitoring\Application\Commands\ComputeRollingDscrAndCrisisIndicatorsCommand;
use Modules\CrisisMonitoring\Application\Handlers\ComputeRollingDscrAndCrisisIndicatorsHandler;
use Throwable;

/**
 * The daily snapshot of the rolling DSCR, the CCII early-warning indicators and the Art. 725 CO guardrails of every company. A company
 * that fails does not stop the others.
 */
final class ComputeCrisisIndicatorsCommand extends Command
{
    protected $signature = 'crisis-monitoring:compute {--tenant= : Compute one company only}';

    protected $description = 'Store the daily rolling DSCR projection and the CCII / Art. 725 CO crisis assessments';

    public function handle(ComputeRollingDscrAndCrisisIndicatorsHandler $handler, TenantContext $context): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), static fn ($query, string $id) => $query->whereKey($id))->get();
        $failed = 0;

        foreach ($tenants as $tenant) {
            try {
                $context->runWithTenant($tenant, static fn (): array => $handler->handle(new ComputeRollingDscrAndCrisisIndicatorsCommand($tenant, null, 'scheduled')));
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
                $this->error($tenant->id.': '.$exception->getMessage());
            }
        }

        $this->info(($tenants->count() - $failed).' of '.$tenants->count().' company snapshot(s) stored.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
