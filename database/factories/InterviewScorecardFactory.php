<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\InterviewScorecard;

/**
 * @extends Factory<InterviewScorecard>
 */
class InterviewScorecardFactory extends Factory
{
    protected $model = InterviewScorecard::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'application_id' => function (array $attrs) {
                return CandidateApplication::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'interviewer_user_id' => (string) Str::uuid(),
            'interviewer_name' => fake()->name(),
            'stage' => 'technical_interview',
            'criteria_scores' => [
                ['criterion' => 'Technical skills', 'score' => 4, 'comment' => 'Solid'],
                ['criterion' => 'Communication', 'score' => 3, 'comment' => null],
            ],
            'overall_score' => 3.5,
            'recommendation' => 'hire',
            'notes' => 'Good candidate',
            'interviewed_at' => now(),
        ];
    }
}
