<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Collaboration\Models\CollabTag;

/**
 * @extends Factory<CollabTag>
 */
final class CollabTagFactory extends Factory
{
    protected $model = CollabTag::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->word(),
            'color' => '#1677ff',
        ];
    }
}
