<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ComprehensiveLookupMasterSeeder extends Seeder
{
    /**
     * Run all multilingual enterprise lookup seeders.
     */
    public function run(): void
    {
        $this->call([
            CountrySeeder::class,
            RegionSeeder::class,
            CurrencyTranslationSeeder::class,
            VatCodeSeeder::class,
            PaymentMethodSeeder::class,
            PaymentTermTranslationSeeder::class,
            DocumentStatusSeeder::class,
            InventoryMovementTypeSeeder::class,
            ExpenseCategorySeeder::class,
            ContactLookupSeeder::class,
            ProjectTaskStatusSeeder::class,
            WorkflowApprovalStatusSeeder::class,
            NotificationTypeSeeder::class,
        ]);
    }
}
