<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Base class of the seeders that write global data: lookups, translations, catalogues, legal reference data. Their rows
 * have no tenant (tenant_id null) and are visible to every tenant. This is the explicit declaration the tenant policy
 * requires: the body of seedGlobalData() runs inside TenantContext::runAsGlobal(), where only the models that allow
 * global records (AllowsGlobalRecords) can be created, and every one of them is stored with a null tenant_id.
 * It also suspends any tenant context left active by an earlier seeder. A seeder that also writes tenant data must keep
 * that part out of seedGlobalData().
 */
abstract class GlobalDataSeeder extends Seeder
{
    final public function run(): void
    {
        $context = app(TenantContext::class);

        $context->runWithoutTenant(fn () => $context->runAsGlobal(fn () => $this->seedGlobalData()));
    }

    abstract protected function seedGlobalData(): void;
}
