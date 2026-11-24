<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class ProjectDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ProjectStatusSeeder::class,
            ProjectLookupSeeder::class,
            ProjectPhaseSeeder::class,
            ProjectMilestoneSeeder::class,
            ProjectRiskCategorySeeder::class,
            ProjectExpenseCategorySeeder::class,
            ProjectForecastCategorySeeder::class,
            ProjectReferenceSeeder::class,
            ProjectTestingSeeder::class,
            ProjectDemoDataSeeder::class,
        ]);
    }
}
