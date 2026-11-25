<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\GdoTradeTerms\Exceptions\GdoTradeTermsException;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Services\AccrualService;

/**
 * Monthly closing of the GDO rebates and contributions for every active company that has live contracts. Safe to repeat: a month
 * that is already posted is left as it is unless --recalculate is given.
 */
final class AccrueGdoTradeTermsCommand extends Command
{
    protected $signature = 'gdo-trade-terms:accrue {--period= : Month to close (Y-m); default the previous month} {--tenant=* : Limit to these tenants} {--recalculate : Reverse and recompute a month that is already posted} {--dry-run : Compute without posting}';

    protected $description = 'Accrues the GDO rebates (PFA) and trade contributions of a month and posts the accrual entry';

    public function handle(TenantContext $tenantContext, AccrualService $accruals): int
    {
        $period = $this->option('period');
        $end = is_string($period) && $period !== '' ? Carbon::createFromFormat('Y-m', $period)->endOfMonth() : now()->subMonthNoOverflow()->endOfMonth();
        $failed = false;

        $tenants = Tenant::query()
            ->where('status', 'active')
            ->where('is_maintenance', false)
            ->when($this->option('tenant'), fn ($query, $ids) => $query->whereIn('id', $ids))
            ->get();

        foreach ($tenants as $tenant) {
            $tenantContext->runWithTenant($tenant, function () use ($tenant, $accruals, $end, &$failed): void {
                if (! GdoContract::query()->whereIn('status', ['active', 'closed'])->exists()) {
                    return;
                }

                try {
                    $result = $accruals->run((string) $tenant->id, $end, (bool) $this->option('recalculate'), (bool) $this->option('dry-run'), null);
                    $this->line("{$tenant->id}: {$result['period_key']} {$result['status']}, accrued {$result['total_accrued']} on {$result['contracts_count']} contract(s)");
                } catch (GdoTradeTermsException $exception) {
                    $failed = true;
                    $this->error("{$tenant->id}: {$exception->errorCode()}");
                }
            });
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
