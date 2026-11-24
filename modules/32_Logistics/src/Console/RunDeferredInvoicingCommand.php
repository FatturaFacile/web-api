<?php

declare(strict_types=1);

namespace Modules\Logistics\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\Logistics\Services\OrderBillingService;

final class RunDeferredInvoicingCommand extends Command
{
    protected $signature = 'logistics:run-deferred-invoicing {--tenant= : Tenant id (default: all)} {--up-to= : Last DDT date (Y-m-d), default end of current month}';

    protected $description = 'Issue cumulative deferred invoices for unbilled delivery notes (one per customer and month).';

    public function handle(TenantContext $context, OrderBillingService $billing): int
    {
        $upTo = $this->option('up-to') !== null ? Carbon::parse((string) $this->option('up-to')) : null;
        $tenants = Tenant::query()->when($this->option('tenant') !== null, fn ($q) => $q->whereKey($this->option('tenant')))->get();

        foreach ($tenants as $tenant) {
            $count = $context->runWithTenant($tenant, fn (): int => count($billing->runDeferredInvoicing($upTo)));
            $this->info("{$tenant->name}: {$count} deferred invoice(s) issued");
        }

        return self::SUCCESS;
    }
}
