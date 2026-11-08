<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Sales\QuoteLifecycleService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Daily expiry of the quotes: every open quote (draft, approved or sent) of every company past its validity date, on the
 * calendar of that company, becomes expired.
 */
class ExpireSalesQuotes extends Command
{
    protected $signature = 'sales:expire-quotes';

    protected $description = 'Expire the open quotes of every tenant past their validity date';

    public function handle(TenantContext $tenantContext, QuoteLifecycleService $quotes): int
    {
        $expired = 0;

        Tenant::query()->each(function (Tenant $tenant) use ($tenantContext, $quotes, &$expired): void {
            $tenantContext->runWithTenant($tenant, function () use ($quotes, $tenant, &$expired): void {
                $expired += $quotes->expireDue((string) $tenant->id);
            });
        });

        $this->info("Quotes expired: {$expired}");

        return self::SUCCESS;
    }
}
