<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\TalentLearning\Models\EngagementSurvey;

/**
 * @extends Factory<EngagementSurvey>
 */
final class EngagementSurveyFactory extends Factory
{
    protected $model = EngagementSurvey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'title' => 'Pulse '.fake()->monthName(),
            'status' => EngagementSurvey::Draft,
            'is_anonymous' => true,
            'min_group_size' => 5,
        ];
    }
}
