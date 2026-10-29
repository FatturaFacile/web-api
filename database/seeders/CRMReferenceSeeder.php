<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class CRMReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LeadSourceSeeder::class,
            OpportunityStageSeeder::class,
            CampaignTypeSeeder::class,
            CustomerCategorySeeder::class,
            CustomerHealthSeeder::class,
            ForecastCategorySeeder::class,
        ]);
    }
}
