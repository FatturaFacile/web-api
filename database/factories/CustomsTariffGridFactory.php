<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Customs\Models\CustomsTariffGrid;

/**
 * @extends Factory<CustomsTariffGrid>
 */
class CustomsTariffGridFactory extends Factory
{
    protected $model = CustomsTariffGrid::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(2, true),
            'service_type' => 'road',
            'currency' => 'CHF',
        ];
    }
}
