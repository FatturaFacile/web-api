<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\Sustainability\Services\KpiService;

/**
 * The example ESRS KPI definitions of the sustainability module for every company, through the module's own idempotent
 * installation (a KPI is matched on its code).
 */
final class SustainabilityReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $context = app(TenantContext::class);

        foreach (Tenant::query()->get() as $tenant) {
            $context->setTenant($tenant);

            try {
                app(KpiService::class)->installDefaults((string) $tenant->id);
            } finally {
                $context->clear();
            }
        }
    }
}
