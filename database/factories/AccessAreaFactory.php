<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\AccessArea;

/**
 * @extends Factory<AccessArea>
 */
class AccessAreaFactory extends Factory
{
    protected $model = AccessArea::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['code' => 'A'.fake()->unique()->numberBetween(1000, 999999), 'name' => 'Main entrance'];
    }
}
