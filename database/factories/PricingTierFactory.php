<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\PricingTier;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;

class PricingTierFactory extends Factory
{
    protected $model = PricingTier::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'plan_id' => SubscriptionPlan::factory(),
            'tier_number' => 1,
            'from_quantity' => 1.0,
            'to_quantity' => 100.0,
            'unit_price' => 15.00,
            'flat_fee' => 0.00,
        ];
    }
}
