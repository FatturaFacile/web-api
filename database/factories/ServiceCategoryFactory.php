<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\ServiceCategory;

class ServiceCategoryFactory extends Factory
{
    protected $model = ServiceCategory::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'CAT-'.strtoupper(Str::random(6)),
            'name' => fake()->words(2, true).' Service',
            'description' => fake()->sentence(),
            'is_active' => true,
            'metadata' => [
                'type' => fake()->randomElement(['it_service', 'field_service', 'customer_support', 'maintenance']),
            ],
        ];
    }
}
