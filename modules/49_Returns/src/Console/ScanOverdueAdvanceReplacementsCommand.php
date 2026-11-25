<?php

declare(strict_types=1);

namespace Modules\Returns\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\Returns\Services\AdvanceReplacementService;

/**
 * Scans all tenants for overdue advance replacements (cross-shipping) and generates penalty invoices.
 */
final class ScanOverdueAdvanceReplacementsCommand extends Command
{
    protected $signature = 'returns:scan-overdue-replacements';

    protected $description = 'Scan advance replacements past their SLA return due date and issue penalty invoices';

    public function handle(AdvanceReplacementService $service, TenantContext $tenantContext): int
    {
        $totalBilled = 0;

        foreach (Tenant::query()->cursor() as $tenant) {
            $tenantContext->runWithTenant($tenant, function () use ($service, $tenant, &$totalBilled): void {
                $totalBilled += $service->processOverdueForTenant((string) $tenant->id);
            });
        }

        $this->info("Processed overdue advance replacements: {$totalBilled} billed.");

        return self::SUCCESS;
    }
}
