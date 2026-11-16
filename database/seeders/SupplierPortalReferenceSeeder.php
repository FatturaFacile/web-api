<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\SupplierPortal\Services\SupplierDocumentService;

/**
 * The default document requirements of the supplier portal for every company, through the module's own idempotent
 * seeding (a requirement is matched on its code).
 */
final class SupplierPortalReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $context = app(TenantContext::class);

        foreach (Tenant::query()->get() as $tenant) {
            $context->setTenant($tenant);

            try {
                app(SupplierDocumentService::class)->seedDefaults((string) $tenant->id);
            } finally {
                $context->clear();
            }
        }
    }
}
