<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\SupplierPortal\Services\DocumentExpiryAlertService;

/**
 * Daily alerts about the supplier documents that are about to expire or have expired, for every company (or the one
 * given), on the calendar of that company.
 */
final class AlertExpiringSupplierDocumentsCommand extends Command
{
    protected $signature = 'supplier-portal:alert-expiring-documents {--tenant= : Only this tenant id}';

    protected $description = 'Alert about supplier documents expiring soon, expire the qualification of suppliers with an expired mandatory document and refresh the risk flags';

    public function handle(TenantContext $tenantContext, DocumentExpiryAlertService $alerts): int
    {
        $totals = ['alerts' => 0, 'qualifications_expired' => 0, 'suppliers_evaluated' => 0];

        Tenant::query()
            ->when($this->option('tenant') !== null, fn ($query) => $query->whereKey((string) $this->option('tenant')))
            ->each(function (Tenant $tenant) use ($tenantContext, $alerts, &$totals): void {
                $result = $tenantContext->runWithTenant($tenant, fn (): array => $alerts->run((string) $tenant->id));

                foreach ($result as $key => $value) {
                    $totals[$key] += $value;
                }
            });

        $this->info("Alerts sent: {$totals['alerts']}, qualifications expired: {$totals['qualifications_expired']}, suppliers evaluated: {$totals['suppliers_evaluated']}");

        return self::SUCCESS;
    }
}
