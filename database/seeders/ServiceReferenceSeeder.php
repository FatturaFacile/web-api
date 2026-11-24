<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class ServiceReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TicketCategorySeeder::class,
            TicketPrioritySeeder::class,
            IncidentTypeSeeder::class,
            ProblemCategorySeeder::class,
            ChangeCategorySeeder::class,
            ServiceCategorySeeder::class,
            TechnicianSkillSeeder::class,
            SLAPolicySeeder::class,
        ]);
    }
}
