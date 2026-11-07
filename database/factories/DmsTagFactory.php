<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Documents\Models\DmsTag;

/**
 * @extends Factory<DmsTag>
 */
final class DmsTagFactory extends Factory
{
    protected $model = DmsTag::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->unique()->word(),
        ];
    }
}
