<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class InvoicingDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CustomerGroupSeeder::class,
            DiscountPolicySeeder::class,
            CustomerCreditProfileSeeder::class,
            CustomerAgreementSeeder::class,
            SalesRepresentativeSeeder::class,
            SalesOpportunitySeeder::class,
            InvoicingReferenceSeeder::class,
            InvoicingDemoDataSeeder::class,
            InvoicingTestingSeeder::class,
        ]);
    }
}
