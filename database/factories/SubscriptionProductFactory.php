<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\SubscriptionProduct;

class SubscriptionProductFactory extends Factory
{
    protected $model = SubscriptionProduct::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'product_code' => 'PROD-'.strtoupper(Str::random(6)),
            'name' => fake()->words(3, true).' Cloud Suite',
            'description' => fake()->sentence(),
            'product_type' => fake()->randomElement(['saas_license', 'recurring_service', 'usage_meter', 'support_plan']),
            'is_active' => true,
        ];
    }
}
