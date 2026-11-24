<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Modules\CRM\Services\SalesPipelineService;

/**
 * Default sales pipelines, stages and loss reasons of every tenant, labelled in it-CH, de-CH, fr-CH, en and it-IT.
 * Idempotent: matched by code, existing configuration is kept and only missing rows and labels are added.
 */
final class SalesPipelineSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(SalesPipelineService::class);

        foreach (Tenant::query()->get() as $tenant) {
            $service->installCatalog((string) $tenant->id);
        }
    }
}
