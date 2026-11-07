<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\CustomField;

/**
 * @extends Factory<CustomField>
 */
final class CustomFieldFactory extends Factory
{
    protected $model = CustomField::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => 'taglia_maglia_'.fake()->unique()->numberBetween(1, 99999),
            'label' => 'Taglia maglia',
            'type' => 'select',
            'options' => ['XS', 'S', 'M', 'L', 'XL'],
            'is_required' => false,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 20),
        ];
    }
}
