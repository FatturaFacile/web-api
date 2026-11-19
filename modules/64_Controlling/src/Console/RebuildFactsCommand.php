<?php

declare(strict_types=1);

namespace Modules\Controlling\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\Controlling\Services\MarginFactSynchronizer;

/**
 * Rebuilds the margin fact table from the posted sales invoices and credit notes.
 */
final class RebuildFactsCommand extends Command
{
    protected $signature = 'bi:rebuild-facts {--tenant= : Tenant id (all tenants without it)} {--from= : First posting date} {--to= : Last posting date}';

    protected $description = 'Rebuild the BI sales margin facts from posted invoices and credit notes';

    public function handle(MarginFactSynchronizer $facts, TenantContext $context): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->get();
        foreach ($tenants as $tenant) {
            $context->setTenant($tenant);
            $rows = $facts->rebuild((string) $tenant->id, $this->option('from') ?: null, $this->option('to') ?: null);
            $this->info("{$tenant->name}: {$rows} fact rows");
        }
        $context->clear();

        return self::SUCCESS;
    }
}
