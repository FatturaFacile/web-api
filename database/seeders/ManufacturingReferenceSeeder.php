<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class ManufacturingReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ManufacturingItemSeeder::class,
            WorkCenterSeeder::class,
            MachineSeeder::class,
            BOMSeeder::class,
            RoutingSeeder::class,
            QualityPlanSeeder::class,
            MRPPolicySeeder::class,
        ]);
    }
}
