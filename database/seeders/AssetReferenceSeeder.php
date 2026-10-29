<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class AssetReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AssetCategorySeeder::class,
            AssetClassSeeder::class,
            AssetConditionSeeder::class,
            AssetStatusSeeder::class,
            AssetLocationSeeder::class,
            DepreciationMethodSeeder::class,
            MaintenanceTypeSeeder::class,
        ]);
    }
}
