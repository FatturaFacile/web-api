<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Enums\RegistryKind;
use Modules\Associations\Models\RegistryType;

/**
 * @extends Factory<RegistryType>
 */
class RegistryTypeFactory extends Factory
{
    protected $model = RegistryType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['code' => 'R'.fake()->unique()->numberBetween(1000, 999999), 'name' => 'Member', 'kind' => RegistryKind::Member];
    }
}
