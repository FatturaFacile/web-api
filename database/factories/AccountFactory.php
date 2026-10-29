<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\Account;

/**
 * @extends Factory<Account>
 */
final class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'name' => fake()->company(),
            'account_number' => 'ACC-'.strtoupper(Str::random(6)),
            'industry' => fake()->randomElement(['Information Technology', 'Manufacturing', 'Finance', 'Healthcare', 'Logistics']),
            'tier' => 'standard',
            'status' => 'active',
            'annual_revenue' => fake()->randomFloat(2, 50000, 1000000),
            'employee_count' => fake()->numberBetween(10, 500),
            'website' => fake()->url(),
            'customer_health_index' => 85.00,
            'churn_risk' => 'low',
            'lifecycle_stage' => 'customer',
            'last_activity_at' => now(),
        ];
    }

    public function healthy(): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_health_index' => 95.00,
            'churn_risk' => 'low',
            'status' => 'active',
            'last_activity_at' => now()->subDays(2),
        ]);
    }

    public function atRisk(): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_health_index' => 45.00,
            'churn_risk' => 'high',
            'status' => 'active',
            'last_activity_at' => now()->subDays(65),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_health_index' => 20.00,
            'churn_risk' => 'critical',
            'status' => 'inactive',
            'last_activity_at' => now()->subDays(120),
        ]);
    }

    public function strategic(): static
    {
        return $this->state(fn (array $attributes) => [
            'tier' => 'strategic',
            'annual_revenue' => 5000000.00,
            'employee_count' => 1500,
            'customer_health_index' => 98.00,
            'churn_risk' => 'low',
        ]);
    }
}
