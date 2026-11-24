<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The reference data of the newer modules (supplier portal, sustainability, returns, credit management and talent
 * and learning). Every seeder is idempotent.
 */
final class NewerModulesReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SupplierPortalReferenceSeeder::class,
            SustainabilityReferenceSeeder::class,
            ReturnsReferenceSeeder::class,
            CreditManagementReferenceSeeder::class,
            TalentLearningReferenceSeeder::class,
        ]);
    }
}
