<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class TreasuryDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BankSeeder::class,
            BankAccountSeeder::class,
            CurrencySeeder::class,
            ExchangeRateSeeder::class,
            PaymentTypeSeeder::class,
            CollectionTypeSeeder::class,
            LiquidityCategorySeeder::class,
            TreasuryReferenceSeeder::class,
            TreasuryTestingSeeder::class,
            TreasuryDemoDataSeeder::class,
        ]);
    }
}
