<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class SubscriptionReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BillingPlanSeeder::class,
            SubscriptionStatusSeeder::class,
            PricingModelSeeder::class,
            RenewalPolicySeeder::class,
            RevenueRecognitionSeeder::class,
            UsageMetricSeeder::class,
        ]);
    }
}
