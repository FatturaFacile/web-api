<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Minimal baseline seeder for automated tests.
 *
 * Avoids heavy translation dictionaries and demo datasets from DatabaseSeeder
 * so RefreshDatabase/LazilyRefreshDatabase runs stay fast on SQLite.
 */
class TestingSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the minimal reference and auth data required by feature tests.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            TenantSeeder::class,
            UserSeeder::class,
            ErpLookupsSeeder::class,
            UnitOfMeasureSeeder::class,
            TreasuryBankingReferenceSeeder::class,
            DocumentSequenceSeeder::class,
            PayrollReferenceSeeder::class,
            FiscalDepreciationRateSeeder::class,
        ]);
    }
}
