<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\SupportPlan;

class SupportPlanFactory extends Factory
{
    protected $model = SupportPlan::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'PLAN-'.strtoupper(Str::random(6)),
            'name' => fake()->randomElement(['Gold Support', 'Silver Support', 'Bronze Support', 'Mission Critical']),
            'tier' => fake()->randomElement(['basic', 'standard', 'premium', 'mission_critical']),
            'response_time_hours' => 4.00,
            'resolution_time_hours' => 24.00,
            'price' => fake()->randomFloat(2, 500, 5000),
            'currency' => 'EUR',
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ];
    }
}
