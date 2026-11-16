<?php

declare(strict_types=1);

namespace Modules\HumanResources\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\HumanResources\Services\CandidateRetentionService;

/**
 * Daily GDPR / nLPD retention run: anonymises the candidates whose privacy consent expired (never hired ones),
 * deletes their CV files and records an audit entry for each of them. Idempotent; `--dry-run` only counts.
 */
final class PurgeExpiredCandidateDataCommand extends Command
{
    protected $signature = 'hr:recruiting:purge-expired-candidates
        {--dry-run : Report how many candidates would be anonymised without changing anything}
        {--tenant= : Restrict the run to one tenant id}';

    protected $description = 'Anonymise recruiting candidates whose privacy consent has expired (GDPR / nLPD retention)';

    public function handle(CandidateRetentionService $retention, TenantContext $tenantContext): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenantOption = $this->option('tenant');

        $tenants = Tenant::query()
            ->when(is_string($tenantOption) && $tenantOption !== '', fn ($query) => $query->whereKey($tenantOption))
            ->orderBy('id')
            ->get();

        if (is_string($tenantOption) && $tenantOption !== '' && $tenants->isEmpty()) {
            $this->error("Tenant '{$tenantOption}' not found.");

            return self::FAILURE;
        }

        $totalCandidates = 0;
        $totalAnonymized = 0;

        foreach ($tenants as $tenant) {
            /** @var array{candidates: int, anonymized: int} $result */
            $result = $tenantContext->runFor($tenant, fn (): array => $retention->purgeExpired($dryRun));

            if ($result['candidates'] > 0) {
                $this->line(sprintf(
                    'Tenant %s: %d expired candidate(s)%s',
                    $tenant->id,
                    $result['candidates'],
                    $dryRun ? ' (dry run, nothing changed)' : ", {$result['anonymized']} anonymised"
                ));
            }

            $totalCandidates += $result['candidates'];
            $totalAnonymized += $result['anonymized'];
        }

        $this->info(sprintf(
            '%s: %d expired candidate(s) found, %d anonymised across %d tenant(s).',
            $dryRun ? 'Dry run' : 'Purge completed',
            $totalCandidates,
            $totalAnonymized,
            $tenants->count()
        ));

        return self::SUCCESS;
    }
}
