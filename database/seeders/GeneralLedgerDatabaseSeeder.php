<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class GeneralLedgerDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            FiscalYearSeeder::class,
            FiscalPeriodSeeder::class,
            ChartOfAccountsSeeder::class,
            VatCodeSeeder::class,
            AccountTypeSeeder::class,
            JournalTypeSeeder::class,
            AccountingReferenceSeeder::class,
            AccountingTestingSeeder::class,
            AccountingDemoDataSeeder::class,
        ]);
    }
}
