<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantClock;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\GeneralLedger\Services\RecurringJournalService;
use Modules\GeneralLedger\Services\ScheduledReversalService;

/**
 * The nightly ledger work of every tenant, up to its own business day: recurring journals due, then automatic
 * reversals due (accruals and deferrals, exchange revaluations, journals with a reversal date). What cannot be posted
 * is reported and the rest goes on; a later run picks it up again, since every step is idempotent.
 */
final class RunScheduledJournalsCommand extends Command
{
    protected $signature = 'general-ledger:run-scheduled {--tenant=* : Limit to these tenants}';

    protected $description = 'Posts the recurring journals and the automatic reversals due, for every tenant';

    public function handle(TenantContext $tenantContext, TenantClock $clock, RecurringJournalService $recurring, ScheduledReversalService $reversals): int
    {
        $failures = 0;
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($query, $ids) => $query->whereIn('id', $ids))->get();

        foreach ($tenants as $tenant) {
            $tenantContext->runWithTenant($tenant, function () use ($tenant, $clock, $recurring, $reversals, &$failures): void {
                $today = $clock->today($tenant);
                $recurringResult = $recurring->runDue((string) $tenant->id, $today, null);
                $reversalResult = $reversals->processDue((string) $tenant->id, $today, null);

                foreach ([...$recurringResult['failed'], ...$reversalResult['failed']] as $failure) {
                    $failures++;
                    $this->error("{$tenant->id}: {$failure['message']}");
                }

                $this->line("{$tenant->id}: ".count($recurringResult['posted']).' recurring runs, '.count($reversalResult['reversed']).' reversals');
            });
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
