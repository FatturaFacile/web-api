<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\Returns\Services\ReturnsSettingsService;

/**
 * The return window and the default reason codes of the returns module for every company, through the module's own
 * idempotent settings (a company that already has reason codes keeps them untouched).
 */
final class ReturnsReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $context = app(TenantContext::class);

        foreach (Tenant::query()->get() as $tenant) {
            $context->setTenant($tenant);

            try {
                app(ReturnsSettingsService::class)->settings((string) $tenant->id);
            } finally {
                $context->clear();
            }
        }
    }
}
