<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;
use Modules\SubscriptionBilling\Models\SubscriptionProduct;

class SubscriptionPlanFactory extends Factory
{
    protected $model = SubscriptionPlan::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'product_id' => SubscriptionProduct::factory(),
            'plan_code' => 'PLAN-'.strtoupper(Str::random(6)),
            'name' => fake()->words(2, true).' Plan',
            'description' => fake()->sentence(),
            'billing_interval' => 'monthly',
            'interval_count' => 1,
            'pricing_model' => 'flat_fee',
            'currency' => 'EUR',
            'setup_fee' => 0.00,
            'base_price' => 120.00,
            'trial_period_days' => 14,
            'min_commitment_cycles' => 1,
            'is_active' => true,
        ];
    }

    public function monthly(): static
    {
        return $this->state(fn () => [
            'billing_interval' => 'monthly',
            'interval_count' => 1,
            'base_price' => 100.00,
        ]);
    }

    public function annual(): static
    {
        return $this->state(fn () => [
            'billing_interval' => 'annual',
            'interval_count' => 1,
            'base_price' => 1000.00,
        ]);
    }

    public function quarterly(): static
    {
        return $this->state(fn () => [
            'billing_interval' => 'quarterly',
            'interval_count' => 1,
            'base_price' => 280.00,
        ]);
    }

    public function tiered(): static
    {
        return $this->state(fn () => [
            'pricing_model' => 'tiered',
        ]);
    }

    public function metered(): static
    {
        return $this->state(fn () => [
            'pricing_model' => 'metered',
        ]);
    }
}
