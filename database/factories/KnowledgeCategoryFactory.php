<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\KnowledgeCategory;

/**
 * @extends Factory<KnowledgeCategory>
 */
class KnowledgeCategoryFactory extends Factory
{
    protected $model = KnowledgeCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(2, true),
            'visibility' => 'public',
            'position' => 100,
        ];
    }
}
