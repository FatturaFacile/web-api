<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class InventoryDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            InventoryLookupSeeder::class,
            InventoryStatusSeeder::class,
            InventoryMovementTypeSeeder::class,
            InventoryReferenceSeeder::class,
            LotStatusSeeder::class,
            SerialStatusSeeder::class,
            InventoryCategorySeeder::class,
            WarehouseSeeder::class,
            InventoryTestingSeeder::class,
            InventoryDemoDataSeeder::class,
        ]);
    }
}
