<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\Invoicing\Services\Receivables\ReceivableItemService;

/**
 * Creates the open items (receivables) of the issued invoices that have none yet; run it once after the deploy that
 * introduces the receivables, or for one company.
 */
final class SyncReceivableItems extends Command
{
    protected $signature = 'receivables:sync {--tenant= : Only this company}';

    protected $description = 'Backfill the receivable open items of the issued sales invoices';

    public function handle(ReceivableItemService $items, TenantContext $context): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($query, $id) => $query->whereKey($id))->get();

        foreach ($tenants as $tenant) {
            $context->setTenant($tenant);
            $count = $items->syncTenant((string) $tenant->id);
            $this->info($tenant->name.': '.$count.' open items');
        }

        $context->clear();

        return self::SUCCESS;
    }
}
