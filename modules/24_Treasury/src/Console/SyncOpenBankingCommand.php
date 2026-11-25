<?php

declare(strict_types=1);

namespace Modules\Treasury\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\Treasury\Models\OpenBankingConsent;
use Modules\Treasury\Services\OpenBankingStatementSync;
use Throwable;

/**
 * Scheduled pull of the movements of every active open banking consent (PSD2 / SIX bLink), tenant by tenant. A failure
 * of one consent is reported and recorded in its sync run; the others still run.
 */
final class SyncOpenBankingCommand extends Command
{
    protected $signature = 'treasury:open-banking-sync {--tenant=* : Limit to these tenants} {--from= : First booking date (Y-m-d)} {--to= : Last booking date (Y-m-d)}';

    protected $description = 'Imports the latest movements of the open banking consents as bank statements';

    public function handle(TenantContext $tenantContext, OpenBankingStatementSync $sync): int
    {
        $failed = 0;
        $tenants = Tenant::query()
            ->where('status', 'active')
            ->where('is_maintenance', false)
            ->when($this->option('tenant'), fn ($query, $ids) => $query->whereIn('id', $ids))
            ->get();

        foreach ($tenants as $tenant) {
            $tenantContext->runWithTenant($tenant, function () use ($tenant, $sync, &$failed): void {
                foreach (OpenBankingConsent::query()->where('status', 'active')->get() as $consent) {
                    if (! $consent->isActive()) {
                        continue;
                    }

                    try {
                        $run = $sync->sync($consent, $this->stringOption('from'), $this->stringOption('to'), null);
                        $this->line("{$tenant->id}: {$consent->iban} fetched {$run->fetched}, imported {$run->imported}, duplicates {$run->duplicates}");
                    } catch (Throwable $e) {
                        $failed++;
                        $this->error("{$tenant->id}: {$consent->iban} failed: {$e->getMessage()}");
                    }
                }
            });
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
