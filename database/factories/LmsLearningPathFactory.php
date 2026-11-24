<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\TalentLearning\Models\LmsLearningPath;

/**
 * @extends Factory<LmsLearningPath>
 */
final class LmsLearningPathFactory extends Factory
{
    protected $model = LmsLearningPath::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(3, true),
            'status' => 'draft',
        ];
    }
}
